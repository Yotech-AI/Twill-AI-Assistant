# Twill AI Assistant

An AI content assistant embedded in the [Twill CMS](https://twillcms.com) admin, with an optional [MCP](https://modelcontextprotocol.io) connector that exposes the same content tools to external clients such as Claude.

Editors chat with it in the admin; it reads your module structure, proposes a plan, and writes drafts for review. It can never publish and can never delete — those are product guarantees enforced in code, not settings.

Requires PHP 8.3+, Laravel 12 or 13, Twill 3.5+.

## Installation

```bash
composer require yotech-ai/twill-cms-ai-assistant
```

```bash
php artisan twill-ai:install && php artisan migrate
```

Then describe your modules in `config/twill-ai.php`, run a queue worker, and save a provider API key on the assistant's Settings page. `php artisan twill-ai:doctor` checks all of it.

There is no provider to register, no view to override and no asset to publish. The package registers its own routes, views, migrations, private upload disk and job queue from your existing Twill configuration, so it adapts to a custom admin path automatically.

Editors reach the assistant two ways: the **Plugins** page, and the floating widget on every admin screen. It deliberately adds no entry of its own to the main navigation — set `twill-ai.ui.navigation_link` to `true` if your editors want one.

## The module registry

`config/twill-ai.php` is the one file you must fill in. **Only modules listed there are reachable by the agent** — everything else in your CMS (users, orders, application data) does not exist from its point of view.

```php
'modules' => [
    'pages' => [
        'label' => 'Pages',
        'description' => 'Standard site pages, served from /{slug}.',
        'model' => App\Models\Page::class,
        'repository' => App\Repositories\PageRepository::class,
        'route' => 'pages',
        'operations' => ['read', 'create', 'update'],   // never 'delete'
        'block_editors' => [
            'default' => ['content-hero', 'content-text', 'content-faq'],
        ],
        'browsers' => [],
        'sync_fields' => [],
        'extra_fields' => [],
    ],
],
```

| Key | Meaning |
|---|---|
| `label` / `description` | Shown to the model. The description is the most effective place to teach it how a module should be written. |
| `model` / `repository` | The Twill module classes. |
| `route` | The Twill route name segment, as passed to `TwillRoutes::module()`. |
| `singleton` | `true` for singleton modules — update-only, never created. |
| `operations` | Subset of `read`, `create`, `update`. There is no delete operation anywhere in this package. |
| `block_editors` | Editor name => allowed block names, mirroring `getForm()`. An unnamed `BlockEditor::make()` is the editor called `default`. |
| `browsers` | Twill related browsers, saved through `twill_related`. |
| `sync_fields` | Plain `belongsToMany` id-array fields synced in `afterSave()`. |
| `extra_fields` | Whitelisted **non-translated** columns the agent may set. Anything not listed is stripped from its payloads — needed for models without `HasTranslation`. |

An empty registry is safe: the assistant runs and answers questions but has no content it may touch.

### Prompts describe *your* CMS

The tool descriptions and system prompt contain worked examples — a JSON payload, an editor name, a relation. These are generated from your registry at runtime, so the model learns your block names and locales rather than another project's. Override any fragment via `twill-ai.prompts.*`, and set `twill-ai.site_description` to tell external MCP clients what the site is.

## Queue worker

Agent runs execute in a queued job so they can take minutes without hitting execution limits:

```bash
php artisan queue:work twill-ai --queue=twill-ai --timeout=620
```

`queue:work` caches code in memory — restart it after any deploy (`php artisan queue:restart`) or the agent runs stale tools. In development prefer `queue:listen`, which reloads per job.

## The MCP connector (optional)

Off by default. It exposes the same content tools to an external MCP client over OAuth 2.1, and stays completely dormant unless **both** `twill-ai.mcp.enabled` is true and `laravel/mcp` is installed — so a site can run the assistant with no OAuth stack at all.

```bash
composer require laravel/mcp "laravel/passport:^13.7.1"
```

> **Security:** use Passport **13.7.1 or newer**. Versions 13.0.0–13.7.0 carry [CVE-2026-39976](https://github.com/advisories/GHSA-wc55-9qj2-7v4h), a high-severity flaw in the token guard — the exact component this connector authenticates on.

Then set `TWILL_AI_MCP_ENABLED=true` and:

1. `php artisan passport:keys`
2. Point `twill.models.user` at a user model that implements `Laravel\Passport\Contracts\OAuthenticatable` — either `TwillAi\Models\TwillUser`, or add `TwillAi\Concerns\ActsAsOAuthUser` to your own Twill user subclass.
3. Set `'guard' => 'twill_users'` in `config/passport.php`, so the connector approval screen recognises a logged-in Twill admin instead of redirecting to your customer login. This setting is global to Passport: if Passport also serves your own customer API, move that API to its own guard first. The package deliberately does **not** change this for you.
4. `php artisan mcp:client-create` to register a connector and the Twill user its drafts are attributed to.

`php artisan twill-ai:doctor` verifies all four. The connector authenticates on its own `twill-mcp` guard rather than claiming `api`, which in most applications belongs to Sanctum.

Registering a client is what grants access: OAuth dynamic client registration lets anyone create a client, but a client with no row in `mcp_clients` is refused.

## Safety guarantees

These are enforced in `PayloadBuilder` and the tool list, not in config, and no setting widens them:

- **Drafts only.** The agent cannot publish, and cannot change the publish state of anything.
- **No deletion.** No delete tool exists in either the chat agent or the MCP server.
- **Registry-bound.** Only registered modules, and only the operations listed for each.
- **Field whitelist.** Non-translated columns not in `extra_fields` are stripped from agent payloads.

`allow_updating_published` only controls whether the agent may edit an entry a human already published; it still cannot alter its publish state. It ships as `null` — see [SEO Suite integration](#seo-suite-integration-optional) — and `false` pins it closed.

## The Plugins page

This package adds a **Plugins** entry to the admin navigation, next to the Media Library, listing every installed Yotech plugin with a link to its own screen. Nothing to configure.

The page is created by whichever Yotech plugin loads first; the rest just add themselves to it. That coordination happens through two container keys — `yotech.twill-plugins.registry` and `yotech.twill-plugins.page-owner` — which carry only PHP built-ins, so each plugin ships its own copy of the code under its own namespace and they still interoperate. Install one Yotech plugin or five: you get exactly one Plugins page, and no plugin depends on any other.


## SEO Suite integration (optional)

With [`yotech-ai/twill-cms-seo-suite`](https://packagist.org/packages/yotech-ai/twill-cms-seo-suite) installed, the assistant gains three tools on both surfaces — the admin chat and the MCP connector:

| Tool | Does |
|---|---|
| `get_seo` | Reads an entry's current metadata, its score, and the assessments explaining that score. |
| `analyze_seo_text` | Scores proposed copy **without saving anything**, so a rewrite can be checked before it is written. |
| `update_seo` | Writes the metadata, through the Suite's own writer, and refreshes its score and sitemap caches. |

Nothing to configure: the integration turns itself on when the Suite is present. `TWILL_AI_SEO_ENABLED=false` turns it off again, and on a site without the Suite none of it exists — no tools, no registry key, and no SEO wording in the prompt.

### What it will not touch

`update_seo` writes seven fields — `seo_title`, `seo_description`, `focus_keyphrase`, `og_title`, `og_description`, `twitter_title`, `twitter_description`.

The indexing controls are refused in code, not by config: `robots_noindex`, `robots_nofollow`, `canonical_url`, `cornerstone` and `schema_type_override`. Deindexing a page or reassigning its canonical is quietly destructive in the same way publishing and deleting are, and this package's rule is that the agent never does the destructive thing.

### Editing published entries

Installing the Suite changes one default. `allow_updating_published` ships as `null`, meaning *follow the Suite*: with it installed the agent may edit entries a human already published, without it the answer stays no.

| `allow_updating_published` | Effect |
|---|---|
| `null` (default) | Published entries editable **only** when the SEO Suite is installed. |
| `true` | Always editable. |
| `false` | Never; drafts only, whatever else is installed. |

Two things hold regardless. The agent still cannot change any entry's publish state, and **new content is always a draft** — the loosened rule is about improving live copy, never about putting new copy live. And every edit to a published entry comes back with `was_published: true` and a warning that the agent is instructed to relay, so a live change is never silent.

## Commands

| Command | Purpose |
|---|---|
| `twill-ai:install` | Publish the config and report the remaining setup steps. |
| `twill-ai:doctor` | Diagnose block registration, host wiring, queue and MCP setup. |
| `twill-ai:refresh-models` | Refresh the provider's model list for the picker. |
| `mcp:client-create` / `mcp:client-list` / `mcp:client-revoke` | Manage MCP connectors. |
| `mcp:doctor` | Diagnose the MCP endpoint, its tools and its clients. |

## Frontend assets

The built Vue app ships in `resources/dist` and is served from a package route with an ETag and a far-future cache header, so it can never go stale after an upgrade. `php artisan vendor:publish --tag=twill-ai-assets` is optional; the views prefer a published copy when one exists.

To rebuild from source: `npm install && npm run build` in `resources/js`.

## License

MIT
