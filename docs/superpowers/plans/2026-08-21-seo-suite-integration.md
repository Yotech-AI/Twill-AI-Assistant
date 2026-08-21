# SEO Suite Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the assistant read the SEO Suite's real scores, score proposed copy before writing it, and set SEO metadata — on both the admin chat and the MCP connector.

**Architecture:** The SEO Suite is an optional dependency gated exactly like the MCP connector (`config` AND `class_exists`, both evaluated in `boot()`). A single adapter — `TwillAi\Seo\SeoBridge` — is the only class permitted to import `TwillSeo\*`; tools, prompts and tests talk to a `SeoBridgeContract`. Three new tools are shared between surfaces, with one-line MCP wrappers.

**Tech Stack:** PHP 8.3+, Laravel 12/13, Twill 3.6, Pest 4 + Orchestra Testbench 11, `yotech-ai/twill-cms-seo-suite ^1.0`.

**Spec:** `docs/superpowers/specs/2026-08-21-seo-suite-integration-design.md`

## Global Constraints

- The agent can never publish, unpublish or delete. Unchanged, non-negotiable.
- New entries are always drafts. `buildForCreate` forces `published = false` with no config path.
- Off-limits SEO meta, refused in code by name: `robots_noindex`, `robots_nofollow`, `canonical_url`, `cornerstone`, `schema_type_override`.
- Writable SEO meta: `seo_title`, `seo_description`, `focus_keyphrase`, `og_title`, `og_description`, `twitter_title`, `twitter_description`.
- Only `TwillAi\Seo\SeoBridge` may import `TwillSeo\*`. Everything else depends on `SeoBridgeContract`.
- The gate is `config('twill-ai.seo.enabled', true) && class_exists(\TwillSeo\Analysis\AnalysisRunner::class)`, evaluated in `boot()` only.
- On a site without the Suite: no SEO tools, no prompt fragment, no registry key, and the assistant otherwise fully working.
- `area17/twill` stays declared `^3.5`. The Suite needs `^3.6`; a 3.5 host simply never opens the gate.
- Run tests with `vendor/bin/pest.bat --no-coverage` (Windows). Style: `vendor/bin/pint.bat`.

---

## File Structure

**Create:**
- `src/Seo/SeoBridgeContract.php` — the interface everything depends on
- `src/Seo/SeoBridge.php` — the only file importing `TwillSeo\*`
- `src/Seo/NullSeoBridge.php` — bound when the gate is closed
- `src/Seo/SeoFields.php` — writable/off-limits whitelists in one place
- `src/Tools/GetSeo.php`, `src/Tools/AnalyzeSeoText.php`, `src/Tools/UpdateSeo.php`
- `src/Mcp/Tools/GetSeo.php`, `src/Mcp/Tools/AnalyzeSeoText.php`, `src/Mcp/Tools/UpdateSeo.php`
- `tests/Fixtures/FakeSeoBridge.php`
- `tests/Feature/Seo/*` — gate, bridge, tools, published-flow

**Modify:**
- `src/TwillAiServiceProvider.php` — the gate and bindings
- `src/Agents/TwillAssistant.php:164` — conditional tool list
- `src/Mcp/Servers/TwillContentServer.php:39,62` — conditional `$tools`, instructions
- `src/Tools/UpdateContent.php:61` — tri-state published resolution + warning
- `src/Services/ModuleRegistry.php` — `seo` key in `describe()`
- `src/Services/PromptComposer.php` — SEO fragment
- `config/twill-ai.php` — `seo` section, `allow_updating_published` default
- `tests/Fixtures/Models/Article.php` — add `HasSeo`
- `tests/TestCase.php` — load the Suite's migrations
- `tests/Feature/Mcp/TwillContentServerTest.php` — tool count
- `docs/test-plan.md` — Test 1.4
- `README.md`, `.github/workflows/tests.yml`, `composer.json`

---

## Phase 1 — The gate and the bridge

### Task 1: Contract, null bridge, gate and config

**Files:**
- Create: `src/Seo/SeoBridgeContract.php`, `src/Seo/NullSeoBridge.php`, `src/Seo/SeoFields.php`
- Modify: `src/TwillAiServiceProvider.php`, `config/twill-ai.php`
- Test: `tests/Feature/Seo/SeoGateTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `SeoBridgeContract` with `available(): bool`, `describe(TwillModelContract $entry, string $locale): array`, `analyzeText(array $paper): array`, `updateMeta(TwillModelContract $entry, string $locale, array $fields): array`. `SeoFields::WRITABLE` and `SeoFields::OFF_LIMITS` (arrays of string). Provider method `seoAvailable(): bool`.

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Seo/SeoGateTest.php
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Seo\NullSeoBridge;

it('binds a bridge that reports availability', function () {
    expect(app(SeoBridgeContract::class))->toBeInstanceOf(SeoBridgeContract::class);
});

it('closes the gate when the host disables it', function () {
    config()->set('twill-ai.seo.enabled', false);

    // Re-resolve through a fresh provider decision rather than the cached binding.
    expect(app()->getProvider(\TwillAi\TwillAiServiceProvider::class))
        ->toBeInstanceOf(\TwillAi\TwillAiServiceProvider::class);
});

it('reports nothing available from the null bridge', function () {
    expect((new NullSeoBridge)->available())->toBeFalse();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest.bat --no-coverage --filter=SeoGate`
Expected: FAIL — `Class "TwillAi\Seo\SeoBridgeContract" not found`

- [ ] **Step 3: Write the contract and whitelists**

```php
// src/Seo/SeoFields.php
namespace TwillAi\Seo;

final class SeoFields
{
    /** Editorial copy the agent may set. */
    public const WRITABLE = [
        'seo_title', 'seo_description', 'focus_keyphrase',
        'og_title', 'og_description', 'twitter_title', 'twitter_description',
    ];

    /**
     * Refused by name, on the same reasoning that stops the agent publishing or
     * deleting: noindex removes a page from search results and canonical_url
     * hands its ranking signals to another URL. Quietly destructive, and
     * reachable from a plausible instruction. Human decisions.
     */
    public const OFF_LIMITS = [
        'robots_noindex', 'robots_nofollow', 'canonical_url',
        'cornerstone', 'schema_type_override',
    ];
}
```

```php
// src/Seo/SeoBridgeContract.php
namespace TwillAi\Seo;

use A17\Twill\Models\Contracts\TwillModelContract;

interface SeoBridgeContract
{
    public function available(): bool;

    /** @return array<string,mixed> meta + a freshly computed report */
    public function describe(TwillModelContract $entry, string $locale): array;

    /** @param array<string,mixed> $paper @return array<string,mixed> */
    public function analyzeText(array $paper): array;

    /** @param array<string,mixed> $fields @return array<string,mixed> */
    public function updateMeta(TwillModelContract $entry, string $locale, array $fields): array;
}
```

```php
// src/Seo/NullSeoBridge.php
namespace TwillAi\Seo;

use A17\Twill\Models\Contracts\TwillModelContract;
use TwillAi\Exceptions\TwillAiException;

/**
 * Bound when the SEO Suite is absent or disabled. Every method throws rather
 * than returning empty: the tools are not registered in that state, so reaching
 * one of these is a wiring bug worth surfacing loudly.
 */
final class NullSeoBridge implements SeoBridgeContract
{
    public function available(): bool
    {
        return false;
    }

    public function describe(TwillModelContract $entry, string $locale): array
    {
        throw new TwillAiException('The SEO Suite is not installed on this site.');
    }

    public function analyzeText(array $paper): array
    {
        throw new TwillAiException('The SEO Suite is not installed on this site.');
    }

    public function updateMeta(TwillModelContract $entry, string $locale, array $fields): array
    {
        throw new TwillAiException('The SEO Suite is not installed on this site.');
    }
}
```

- [ ] **Step 4: Add the gate to the provider**

In `src/TwillAiServiceProvider.php`, add alongside `mcpAvailable()`:

```php
/**
 * Default true, unlike mcp.enabled: the connector opens a network surface and
 * must be chosen, whereas SEO is inert unless the Suite is installed, so
 * class_exists is a sufficient guard.
 */
protected function seoAvailable(): bool
{
    return (bool) config('twill-ai.seo.enabled', true)
        && class_exists(\TwillSeo\Analysis\AnalysisRunner::class);
}
```

And in `boot()`, after `registerMcp()`:

```php
$this->registerSeo();
```

```php
protected function registerSeo(): void
{
    // Bound in boot(), with the gate evaluated once, for the reason the MCP
    // guard had to be fixed: a gate read in two lifecycle phases can disagree.
    $this->app->singleton(Seo\SeoBridgeContract::class, fn () => $this->seoAvailable()
        ? new Seo\SeoBridge(
            app(\TwillSeo\Services\PaperFactory::class),
            app(\TwillSeo\Analysis\AnalysisRunner::class),
        )
        : new Seo\NullSeoBridge);
}
```

- [ ] **Step 5: Add config**

In `config/twill-ai.php`, after the `mcp` section:

```php
/*
|--------------------------------------------------------------------------
| SEO Suite integration
|--------------------------------------------------------------------------
|
| When yotech-ai/twill-cms-seo-suite is installed, the assistant gains three
| tools: read an entry's score and failing assessments, score proposed copy
| before writing it, and set SEO metadata. Inert without the Suite, so this
| defaults on — set false to keep the Suite installed but out of the agent's
| reach.
|
*/

'seo' => [
    'enabled' => env('TWILL_AI_SEO_ENABLED', true),
],
```

- [ ] **Step 6: Run to verify it passes**

Run: `vendor/bin/pest.bat --no-coverage --filter=SeoGate`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add src/Seo config/twill-ai.php src/TwillAiServiceProvider.php tests/Feature/Seo/SeoGateTest.php
git commit -m "Add the SEO bridge contract and its gate"
```

---

### Task 2: The real bridge

**Files:**
- Create: `src/Seo/SeoBridge.php`
- Modify: `composer.json` (require-dev), `tests/TestCase.php` (Suite migrations), `tests/Fixtures/Models/Article.php` (HasSeo)
- Test: `tests/Feature/Seo/SeoBridgeTest.php`

**Interfaces:**
- Consumes: `SeoBridgeContract`, `SeoFields` from Task 1
- Produces: `SeoBridge` implementing the contract against `TwillSeo\Services\PaperFactory` and `TwillSeo\Analysis\AnalysisRunner`

- [ ] **Step 1: Add the dependency and fixture wiring**

```bash
composer require --dev "yotech-ai/twill-cms-seo-suite:^1.0"
```

In `tests/Fixtures/Models/Article.php`, add the trait so the fixture CMS has a module WITH seo (`Singleton` deliberately keeps none, giving the "no SEO surface" path a real subject):

```php
use TwillSeo\Models\Behaviors\HasSeo;

// inside class Article:
use HasSeo;
```

In `tests/TestCase.php`, inside `defineDatabaseMigrations()`, add to the paths array before the `is_dir` loop runs:

```php
$vendor.'/yotech-ai/twill-cms-seo-suite/database/migrations',
```

- [ ] **Step 2: Write the failing test**

```php
// tests/Feature/Seo/SeoBridgeTest.php
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Tests\Fixtures\Models\Article;

it('reports available when the Suite is installed', function () {
    expect(app(SeoBridgeContract::class)->available())->toBeTrue();
});

it('scores arbitrary text without touching the database', function () {
    $before = Article::count();

    $report = app(SeoBridgeContract::class)->analyzeText([
        'text' => '<p>Focus timers help remote teams keep deep work uninterrupted.</p>',
        'keyphrase' => 'deep work',
        'locale' => 'en',
    ]);

    expect($report)->toHaveKeys(['locale', 'seo', 'readability'])
        ->and($report['seo'])->toHaveKeys(['score', 'rating', 'results'])
        ->and(Article::count())->toBe($before);
});

it('describes a saved entry with meta and a fresh report', function () {
    $article = twillAiCreateArticle();

    $described = app(SeoBridgeContract::class)->describe($article->fresh(), 'en');

    expect($described)->toHaveKeys(['meta', 'report'])
        ->and($described['meta'])->toHaveKey('seo_title')
        ->and($described['report'])->toHaveKey('seo');
});
```

- [ ] **Step 3: Run to verify it fails**

Run: `vendor/bin/pest.bat --no-coverage --filter=SeoBridge`
Expected: FAIL — `Class "TwillAi\Seo\SeoBridge" not found`

- [ ] **Step 4: Write the bridge**

```php
// src/Seo/SeoBridge.php
namespace TwillAi\Seo;

use A17\Twill\Models\Contracts\TwillModelContract;
use TwillAi\Exceptions\TwillAiException;
use TwillSeo\Analysis\AnalysisRunner;
use TwillSeo\Analysis\Paper\Paper;
use TwillSeo\Services\PaperFactory;

/**
 * The ONLY class in this package permitted to import TwillSeo\*. A Suite
 * refactor breaks this file and nothing else.
 */
final class SeoBridge implements SeoBridgeContract
{
    public function __construct(
        private readonly PaperFactory $papers,
        private readonly AnalysisRunner $runner,
    ) {}

    public function available(): bool
    {
        return true;
    }

    public function describe(TwillModelContract $entry, string $locale): array
    {
        $this->assertHasSeo($entry);

        // Reuses the Suite's own model->Paper resolution rather than
        // reimplementing content extraction, so analysis matches what the SEO
        // panel shows for the same entry.
        $build = $this->papers->fromModel($entry, $locale);

        $seo = method_exists($entry, 'seo') ? $entry->seo($locale) : null;

        return [
            'meta' => collect(SeoFields::WRITABLE)
                ->mapWithKeys(fn (string $field) => [$field => $seo?->{$field}])
                ->all(),
            'content_source' => $build->contentSource,
            'report' => $this->runner->analyze($build->paper)->toArray(),
        ];
    }

    public function analyzeText(array $paper): array
    {
        return $this->runner->analyze(new Paper(
            text: (string) ($paper['text'] ?? ''),
            keyword: (string) ($paper['keyphrase'] ?? ''),
            title: (string) ($paper['title'] ?? ''),
            description: (string) ($paper['description'] ?? ''),
            slug: (string) ($paper['slug'] ?? ''),
            locale: (string) ($paper['locale'] ?? config('app.locale', 'en')),
        ))->toArray();
    }

    /**
     * Mirrors HandleSeo::afterSaveHandleSeo, which is the Suite's own writer:
     * get-or-create the entry, set columns through translationOrNew(), save
     * only dirty translations, then refresh the caches.
     *
     * The cache calls are not optional. Writing meta without them leaves the
     * SEO panel and the content listing showing a stale score, and a sitemap
     * page that no longer matches the entry. Both are wrapped exactly as the
     * Suite wraps them: an analysis or sitemap failure must never take down a
     * write that already succeeded.
     */
    public function updateMeta(TwillModelContract $entry, string $locale, array $fields): array
    {
        $this->assertHasSeo($entry);

        $seoEntry = $entry->seoEntry()->firstOrCreate();

        foreach ($fields as $column => $value) {
            $trimmed = is_string($value) ? trim($value) : $value;

            $seoEntry->translationOrNew($locale)->{$column} = ($trimmed === '' ? null : $trimmed);
        }

        foreach ($seoEntry->translations as $translation) {
            if ($translation->isDirty()) {
                $translation->save();
            }
        }

        try {
            app(\TwillSeo\Services\ScoreCache::class)->refresh($entry);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            app(\TwillSeo\Services\Sitemap\SitemapCache::class)->forgetFor($entry);
        } catch (\Throwable $e) {
            report($e);
        }

        return array_keys($fields);
    }

    private function assertHasSeo(TwillModelContract $entry): void
    {
        if (! method_exists($entry, 'seoEntry')) {
            throw new TwillAiException(sprintf(
                'The "%s" module has no SEO surface — its model does not use HasSeo.',
                class_basename($entry)
            ));
        }
    }
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/pest.bat --no-coverage --filter=SeoBridge`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Seo/SeoBridge.php composer.json composer.lock tests/
git commit -m "Add the SEO bridge over the Suite's PaperFactory and AnalysisRunner"
```

---

## Phase 2 — Tools

### Task 3: `get_seo`

**Files:**
- Create: `src/Tools/GetSeo.php`
- Create: `tests/Fixtures/FakeSeoBridge.php`
- Test: `tests/Feature/Seo/GetSeoToolTest.php`

**Interfaces:**
- Consumes: `SeoBridgeContract`, `ModuleRegistry`
- Produces: tool named `get_seo`, arguments `module`, `id`, `locale?`

- [ ] **Step 1: Write the fake bridge**

```php
// tests/Fixtures/FakeSeoBridge.php
namespace TwillAi\Tests\Fixtures;

use A17\Twill\Models\Contracts\TwillModelContract;
use TwillAi\Seo\SeoBridgeContract;

/**
 * Lets tool behaviour be tested without booting the Suite, and records calls so
 * a test can assert the tool passed through what it claimed to.
 */
final class FakeSeoBridge implements SeoBridgeContract
{
    public array $calls = [];

    public function __construct(public bool $available = true) {}

    public function available(): bool
    {
        return $this->available;
    }

    public function describe(TwillModelContract $entry, string $locale): array
    {
        $this->calls[] = ['describe', $entry->id, $locale];

        return [
            'meta' => ['seo_title' => 'Stored title', 'focus_keyphrase' => 'deep work'],
            'content_source' => 'blocks',
            'report' => ['locale' => $locale, 'seo' => ['score' => 42, 'rating' => 'ok', 'results' => []]],
        ];
    }

    public function analyzeText(array $paper): array
    {
        $this->calls[] = ['analyzeText', $paper];

        return ['locale' => $paper['locale'] ?? 'en', 'seo' => ['score' => 71, 'rating' => 'good', 'results' => []]];
    }

    public function updateMeta(TwillModelContract $entry, string $locale, array $fields): array
    {
        $this->calls[] = ['updateMeta', $entry->id, $locale, $fields];

        return array_keys($fields);
    }
}
```

- [ ] **Step 2: Write the failing test**

```php
// tests/Feature/Seo/GetSeoToolTest.php
use Laravel\Ai\Tools\Request;
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Tests\Fixtures\FakeSeoBridge;
use TwillAi\Tools\GetSeo;

beforeEach(function () {
    $this->bridge = new FakeSeoBridge;
    app()->instance(SeoBridgeContract::class, $this->bridge);
});

it('returns stored meta and a fresh report for an entry', function () {
    $article = twillAiCreateArticle();

    $result = (string) app(GetSeo::class)->handle(new Request([
        'module' => 'articles',
        'id' => $article->id,
        'locale' => 'en',
    ]));

    expect($result)->toContain('Stored title')
        ->toContain('"score":42');
});

it('refuses a module the registry does not list', function () {
    $result = (string) app(GetSeo::class)->handle(new Request([
        'module' => 'supportTickets',
        'id' => 1,
    ]));

    expect($result)->toContain('Unknown module');
});
```

- [ ] **Step 3: Run to verify it fails**

Run: `vendor/bin/pest.bat --no-coverage --filter=GetSeoTool`
Expected: FAIL — `Class "TwillAi\Tools\GetSeo" not found`

- [ ] **Step 4: Write the tool**

```php
// src/Tools/GetSeo.php
namespace TwillAi\Tools;

use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\Response;
use Laravel\Ai\Tools\Tool;
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Tools\Concerns\HandlesToolErrors;

class GetSeo extends Tool
{
    use HandlesToolErrors;

    public function __construct(
        protected ModuleRegistry $registry,
        protected SeoBridgeContract $seo,
    ) {}

    public function name(): string
    {
        return 'get_seo';
    }

    public function description(): string
    {
        return 'Read an entry\'s SEO metadata and a freshly computed analysis: overall score and rating for SEO and readability, plus each assessment with the guidance explaining why it passed or failed. Read this before proposing changes to existing copy.';
    }

    public function handle(Request $request): Response
    {
        return $this->guard(function () use ($request) {
            $module = (string) $request['module'];
            $this->registry->assertAllows($module, 'read');

            $locale = (string) ($request['locale'] ?? $this->registry->locales()[0]);
            $entry = $this->registry->modelInstance($module)->newQuery()->findOrFail((int) $request['id']);

            return Response::text(json_encode(
                $this->seo->describe($entry, $locale),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
        });
    }
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/pest.bat --no-coverage --filter=GetSeoTool`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Tools/GetSeo.php tests/
git commit -m "Add the get_seo tool"
```

---

### Task 4: `analyze_seo_text`

**Files:**
- Create: `src/Tools/AnalyzeSeoText.php`
- Test: `tests/Feature/Seo/AnalyzeSeoTextToolTest.php`

**Interfaces:**
- Consumes: `SeoBridgeContract`
- Produces: tool `analyze_seo_text`, arguments `text`, `keyphrase`, `title?`, `description?`, `slug?`, `locale?`

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Seo/AnalyzeSeoTextToolTest.php
use Laravel\Ai\Tools\Request;
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Tests\Fixtures\FakeSeoBridge;
use TwillAi\Tests\Fixtures\Models\Article;
use TwillAi\Tools\AnalyzeSeoText;

beforeEach(function () {
    $this->bridge = new FakeSeoBridge;
    app()->instance(SeoBridgeContract::class, $this->bridge);
});

it('scores proposed text and writes nothing', function () {
    $before = Article::count();

    $result = (string) app(AnalyzeSeoText::class)->handle(new Request([
        'text' => '<p>Deep work needs uninterrupted time.</p>',
        'keyphrase' => 'deep work',
    ]));

    expect($result)->toContain('"score":71')
        ->and(Article::count())->toBe($before)
        ->and($this->bridge->calls[0][0])->toBe('analyzeText');
});

it('requires text and a keyphrase', function () {
    $result = (string) app(AnalyzeSeoText::class)->handle(new Request(['text' => '']));

    expect($result)->toContain('required');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest.bat --no-coverage --filter=AnalyzeSeoTextTool`
Expected: FAIL — class not found

- [ ] **Step 3: Write the tool**

```php
// src/Tools/AnalyzeSeoText.php
namespace TwillAi\Tools;

use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\Response;
use Laravel\Ai\Tools\Tool;
use TwillAi\Exceptions\TwillAiException;
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Tools\Concerns\HandlesToolErrors;

class AnalyzeSeoText extends Tool
{
    use HandlesToolErrors;

    public function __construct(protected SeoBridgeContract $seo) {}

    public function name(): string
    {
        return 'analyze_seo_text';
    }

    public function description(): string
    {
        return 'Score proposed copy against a focus keyphrase WITHOUT saving anything. Use this to check a rewrite before calling update_content, especially on a published page — it lets you iterate on wording without writing to a live entry.';
    }

    public function handle(Request $request): Response
    {
        return $this->guard(function () use ($request) {
            $text = trim((string) ($request['text'] ?? ''));
            $keyphrase = trim((string) ($request['keyphrase'] ?? ''));

            if ($text === '' || $keyphrase === '') {
                throw new TwillAiException('Both "text" and "keyphrase" are required.');
            }

            return Response::text(json_encode($this->seo->analyzeText([
                'text' => $text,
                'keyphrase' => $keyphrase,
                'title' => (string) ($request['title'] ?? ''),
                'description' => (string) ($request['description'] ?? ''),
                'slug' => (string) ($request['slug'] ?? ''),
                'locale' => (string) ($request['locale'] ?? config('app.locale', 'en')),
            ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        });
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/pest.bat --no-coverage --filter=AnalyzeSeoTextTool`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Tools/AnalyzeSeoText.php tests/
git commit -m "Add the analyze_seo_text tool"
```

---

### Task 5: `update_seo` and the field whitelist

**Files:**
- Create: `src/Tools/UpdateSeo.php`
- Test: `tests/Feature/Seo/UpdateSeoToolTest.php`

**Interfaces:**
- Consumes: `SeoBridgeContract`, `ModuleRegistry`, `SeoFields`
- Produces: tool `update_seo`, arguments `module`, `id`, `locale`, `fields`

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Seo/UpdateSeoToolTest.php
use Laravel\Ai\Tools\Request;
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Seo\SeoFields;
use TwillAi\Tests\Fixtures\FakeSeoBridge;
use TwillAi\Tools\UpdateSeo;

beforeEach(function () {
    $this->bridge = new FakeSeoBridge;
    app()->instance(SeoBridgeContract::class, $this->bridge);
});

it('writes whitelisted meta fields', function () {
    $article = twillAiCreateArticle();

    $result = (string) app(UpdateSeo::class)->handle(new Request([
        'module' => 'articles',
        'id' => $article->id,
        'locale' => 'en',
        'fields' => ['seo_title' => 'Better title', 'focus_keyphrase' => 'deep work'],
    ]));

    expect($result)->toContain('"updated":true')
        ->and($this->bridge->calls[0][3])
        ->toBe(['seo_title' => 'Better title', 'focus_keyphrase' => 'deep work']);
});

it('refuses every off-limits field by name', function (string $field) {
    $article = twillAiCreateArticle();

    $result = (string) app(UpdateSeo::class)->handle(new Request([
        'module' => 'articles',
        'id' => $article->id,
        'locale' => 'en',
        'fields' => [$field => '1'],
    ]));

    // Named, not silently dropped — matching PayloadBuilder's existing
    // "Unknown field" behaviour so the agent can correct itself.
    expect($result)->toContain($field)
        ->and($this->bridge->calls)->toBeEmpty();
})->with(SeoFields::OFF_LIMITS);

it('refuses a field that is neither writable nor known', function () {
    $article = twillAiCreateArticle();

    $result = (string) app(UpdateSeo::class)->handle(new Request([
        'module' => 'articles',
        'id' => $article->id,
        'locale' => 'en',
        'fields' => ['not_a_field' => 'x'],
    ]));

    expect($result)->toContain('not_a_field');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest.bat --no-coverage --filter=UpdateSeoTool`
Expected: FAIL — class not found

- [ ] **Step 3: Write the tool**

```php
// src/Tools/UpdateSeo.php
namespace TwillAi\Tools;

use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\Response;
use Laravel\Ai\Tools\Tool;
use TwillAi\Exceptions\TwillAiException;
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Seo\SeoFields;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Tools\Concerns\HandlesToolErrors;

class UpdateSeo extends Tool
{
    use HandlesToolErrors;

    public function __construct(
        protected ModuleRegistry $registry,
        protected SeoBridgeContract $seo,
    ) {}

    public function name(): string
    {
        return 'update_seo';
    }

    public function description(): string
    {
        return 'Set an entry\'s SEO metadata: seo_title, seo_description, focus_keyphrase, og_title, og_description, twitter_title, twitter_description. Body content is changed with update_content instead. Indexing controls and canonical URLs cannot be set here.';
    }

    public function handle(Request $request): Response
    {
        return $this->guard(function () use ($request) {
            $module = (string) $request['module'];
            $this->registry->assertAllows($module, 'update');

            $fields = $request['fields'] ?? [];

            if (! is_array($fields) || $fields === []) {
                throw new TwillAiException('The "fields" argument must be an object of field => value.');
            }

            $this->assertWritable($fields);

            $locale = (string) ($request['locale'] ?? $this->registry->locales()[0]);
            $entry = $this->registry->modelInstance($module)->newQuery()->findOrFail((int) $request['id']);

            $changed = $this->seo->updateMeta($entry, $locale, $fields);

            return Response::text(json_encode([
                'updated' => true,
                'fields' => $changed,
                'edit_url' => $this->registry->editUrl($module, $entry->id),
            ], JSON_UNESCAPED_SLASHES));
        });
    }

    /** @param array<string,mixed> $fields */
    protected function assertWritable(array $fields): void
    {
        $errors = [];

        foreach (array_keys($fields) as $field) {
            if (in_array($field, SeoFields::OFF_LIMITS, true)) {
                $errors[] = sprintf(
                    'Field "%s" cannot be set by the assistant. Indexing controls, canonical URLs and schema overrides are human decisions.',
                    $field
                );

                continue;
            }

            if (! in_array($field, SeoFields::WRITABLE, true)) {
                $errors[] = sprintf(
                    'Unknown SEO field "%s". Allowed: %s.',
                    $field,
                    implode(', ', SeoFields::WRITABLE)
                );
            }
        }

        if ($errors !== []) {
            throw TwillAiException::withErrors($errors);
        }
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/pest.bat --no-coverage --filter=UpdateSeoTool`
Expected: PASS — including all five off-limits cases

- [ ] **Step 5: Commit**

```bash
git add src/Tools/UpdateSeo.php tests/
git commit -m "Add the update_seo tool with an enforced field whitelist"
```

---

### Task 6: Register the tools on the admin chat

**Files:**
- Modify: `src/Agents/TwillAssistant.php:164-178`
- Test: `tests/Feature/Seo/SeoToolRegistrationTest.php`

**Interfaces:**
- Consumes: `GetSeo`, `AnalyzeSeoText`, `UpdateSeo`, `SeoBridgeContract`
- Produces: nothing new

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Seo/SeoToolRegistrationTest.php
use TwillAi\Agents\TwillAssistant;
use TwillAi\Models\Chat;
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Tests\Fixtures\FakeSeoBridge;

function assistantToolNames(): array
{
    $chat = new Chat(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6']);

    return collect((new TwillAssistant($chat))->tools())
        ->map(fn ($tool) => $tool->name())
        ->all();
}

it('offers the SEO tools when the bridge is available', function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: true));

    expect(assistantToolNames())
        ->toContain('get_seo', 'analyze_seo_text', 'update_seo');
});

it('offers none of them when it is not', function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: false));

    expect(assistantToolNames())
        ->not->toContain('get_seo')
        ->not->toContain('analyze_seo_text')
        ->not->toContain('update_seo')
        // ...and the assistant still works.
        ->toContain('update_content');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest.bat --no-coverage --filter=SeoToolRegistration`
Expected: FAIL — `get_seo` not in the list

- [ ] **Step 3: Make the tool list conditional**

Replace `TwillAssistant::tools()`:

```php
public function tools(): iterable
{
    $tools = [
        app(ListModules::class),
        app(GetModuleSchema::class),
        app(ListBlocks::class),
        app(SearchMedia::class),
        app(UseAttachmentAsMedia::class),
        app(SearchContent::class),
        app(GetContent::class),
        app(CreateContent::class),
        app(UpdateContent::class),
        // NOTE: deliberately no delete tool. Do not add one.
    ];

    // Present only when the SEO Suite is installed and enabled; asked of the
    // bridge rather than re-reading config, so registration and behaviour can
    // never disagree about whether SEO is available.
    if (app(SeoBridgeContract::class)->available()) {
        $tools[] = app(GetSeo::class);
        $tools[] = app(AnalyzeSeoText::class);
        $tools[] = app(UpdateSeo::class);
    }

    return $tools;
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/pest.bat --no-coverage --filter=SeoToolRegistration`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Agents/TwillAssistant.php tests/
git commit -m "Offer the SEO tools on the admin chat when the Suite is present"
```

---

## Phase 3 — Published edits and the enforced warning

### Task 7: Tri-state `allow_updating_published`

**Files:**
- Create: `src/Seo/PublishedEditPolicy.php`
- Modify: `src/Tools/UpdateContent.php:61-65`, `config/twill-ai.php:150`
- Test: `tests/Feature/Seo/PublishedEditPolicyTest.php`

**Interfaces:**
- Consumes: `SeoBridgeContract`
- Produces: `PublishedEditPolicy::allows(): bool`

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Seo/PublishedEditPolicyTest.php
use TwillAi\Seo\PublishedEditPolicy;
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Tests\Fixtures\FakeSeoBridge;

it('refuses when the host said false, Suite or not', function () {
    config()->set('twill-ai.allow_updating_published', false);
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: true));

    expect(app(PublishedEditPolicy::class)->allows())->toBeFalse();
});

it('permits when the host said true, Suite or not', function () {
    config()->set('twill-ai.allow_updating_published', true);
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: false));

    expect(app(PublishedEditPolicy::class)->allows())->toBeTrue();
});

it('permits on null when the Suite is installed', function () {
    config()->set('twill-ai.allow_updating_published', null);
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: true));

    expect(app(PublishedEditPolicy::class)->allows())->toBeTrue();
});

it('refuses on null when it is not', function () {
    config()->set('twill-ai.allow_updating_published', null);
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: false));

    expect(app(PublishedEditPolicy::class)->allows())->toBeFalse();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest.bat --no-coverage --filter=PublishedEditPolicy`
Expected: FAIL — class not found

- [ ] **Step 3: Write the policy**

```php
// src/Seo/PublishedEditPolicy.php
namespace TwillAi\Seo;

/**
 * May the agent edit an entry a human already published?
 *
 * Three states, because two could not express "decide from context":
 *   true  — always permitted
 *   false — never permitted
 *   null  — permitted only when the SEO Suite is installed
 *
 * null is the shipped default. Without the Suite it behaves exactly as false
 * did, so no existing site changes behaviour; a host that published the config
 * has a literal false in their file and keeps refusing until they say otherwise.
 *
 * Creating is unaffected — new entries are always drafts, with no config path.
 */
final class PublishedEditPolicy
{
    public function __construct(private readonly SeoBridgeContract $seo) {}

    public function allows(): bool
    {
        $configured = config('twill-ai.allow_updating_published');

        if (is_bool($configured)) {
            return $configured;
        }

        return $this->seo->available();
    }
}
```

- [ ] **Step 4: Use it in UpdateContent**

Replace lines 61-65 of `src/Tools/UpdateContent.php`:

```php
if ($entry->published && ! app(\TwillAi\Seo\PublishedEditPolicy::class)->allows()) {
    throw new TwillAiException(
        'This entry is PUBLISHED and live. You may only edit drafts. Ask the editor to make the change themselves, or to enable twill-ai.allow_updating_published.'
    );
}
```

- [ ] **Step 5: Change the config default**

In `config/twill-ai.php`, replace the `allow_updating_published` line and extend its comment block:

```php
/*
 | null  = permitted only when the SEO Suite is installed
 | true  = always permitted
 | false = never permitted
 |
 | null is the default and behaves exactly like false without the Suite, so no
 | existing site changes behaviour. Creating is unaffected: new entries are
 | always drafts and that is not configurable.
 */
'allow_updating_published' => null,
```

- [ ] **Step 6: Run to verify it passes**

Run: `vendor/bin/pest.bat --no-coverage --filter=PublishedEditPolicy`
Expected: PASS — all four combinations

- [ ] **Step 7: Run the whole suite for regressions**

Run: `vendor/bin/pest.bat --no-coverage`
Expected: PASS. `PayloadGuardsTest` and `ContentRoundTripTest` both assert the old refusal — they run without the Suite bound in `TestCase`, so `null` resolves to refuse and they keep passing.

- [ ] **Step 8: Commit**

```bash
git add src/Seo/PublishedEditPolicy.php src/Tools/UpdateContent.php config/twill-ai.php tests/
git commit -m "Permit published edits when the SEO Suite is installed"
```

---

### Task 8: The enforced live-content warning

**Files:**
- Modify: `src/Tools/UpdateContent.php`, `src/Tools/UpdateSeo.php`
- Test: `tests/Feature/Seo/LiveContentWarningTest.php`

**Interfaces:**
- Consumes: `PublishedEditPolicy`
- Produces: `was_published` (bool) and `warning` (string) keys on both tools' success payloads

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Seo/LiveContentWarningTest.php
use Laravel\Ai\Tools\Request;
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Tests\Fixtures\FakeSeoBridge;
use TwillAi\Tools\UpdateContent;

beforeEach(function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: true));
    config()->set('twill-ai.allow_updating_published', null);
});

it('flags an edit to a live entry so the agent must relay it', function () {
    $article = twillAiCreateArticle();
    $article->forceFill(['published' => true])->save();

    $result = (string) app(UpdateContent::class)->handle(new Request([
        'module' => 'articles',
        'id' => $article->id,
        'payload' => json_encode(['fields' => ['title' => ['en' => 'Revised']]]),
    ]));

    // Machine-readable, so it survives over MCP where there is no UI and the
    // agent cannot answer without having seen it.
    expect($result)->toContain('"was_published":true')
        ->toContain('PUBLISHED');
});

it('does not flag a draft edit', function () {
    $article = twillAiCreateArticle();

    $result = (string) app(UpdateContent::class)->handle(new Request([
        'module' => 'articles',
        'id' => $article->id,
        'payload' => json_encode(['fields' => ['title' => ['en' => 'Revised']]]),
    ]));

    expect($result)->toContain('"was_published":false')
        ->not->toContain('PUBLISHED and live');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest.bat --no-coverage --filter=LiveContentWarning`
Expected: FAIL — no `was_published` key in the payload

- [ ] **Step 3: Add the flag to both tools**

In `src/Tools/UpdateContent.php`, capture the state before writing and add both keys to the success payload:

```php
$wasPublished = (bool) $entry->published;
```

```php
'was_published' => $wasPublished,
'warning' => $wasPublished
    ? 'This entry is PUBLISHED and live. The change is now visible to visitors. Tell the editor you changed live content.'
    : null,
```

Apply the identical pair in `src/Tools/UpdateSeo.php`, reading `$entry->published` before `updateMeta()`.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/pest.bat --no-coverage --filter=LiveContentWarning`
Expected: PASS

- [ ] **Step 5: Reinforce it in the prompt**

In `src/Agents/TwillAssistant.php` instructions, add one line. The prompt is reinforcement — the tool result is the mechanism:

```
When a tool result carries "was_published": true, you MUST tell the editor that you changed content that is live on the public site.
```

- [ ] **Step 6: Commit**

```bash
git add src/Tools/UpdateContent.php src/Tools/UpdateSeo.php src/Agents/TwillAssistant.php tests/
git commit -m "Flag live-content edits in the tool result"
```

---

## Phase 4 — The MCP surface

### Task 9: MCP wrappers and the tool-count contract

**Files:**
- Create: `src/Mcp/Tools/GetSeo.php`, `src/Mcp/Tools/AnalyzeSeoText.php`, `src/Mcp/Tools/UpdateSeo.php`
- Modify: `src/Mcp/Servers/TwillContentServer.php:39,62`
- Modify: `tests/Feature/Mcp/TwillContentServerTest.php`
- Modify: `docs/test-plan.md` (Test 1.4)

**Interfaces:**
- Consumes: the three `TwillAi\Tools` classes, `WrappedTwillAiTool`
- Produces: three MCP tools; `TwillContentServer` exposing 8 or 11 tools depending on the gate

- [ ] **Step 1: Write the failing test**

Replace the count assertion in `tests/Feature/Mcp/TwillContentServerTest.php`. It currently reads the property's *default* via `ReflectionProperty::getDefaultValue()`, which cannot see a constructor-assigned list — so it must instantiate the server:

```php
function mcpToolNames(): array
{
    $server = app(TwillContentServer::class);

    $property = new ReflectionProperty($server, 'tools');
    $property->setAccessible(true);

    return collect($property->getValue($server))
        ->map(fn (string $tool) => app($tool)->name())
        ->all();
}

it('registers the eight content tools plus SEO when the Suite is present', function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: true));

    expect(mcpToolNames())->toEqualCanonicalizing([
        'list_modules', 'get_module_schema', 'list_blocks', 'search_content',
        'get_content', 'search_media', 'create_content', 'update_content',
        'get_seo', 'analyze_seo_text', 'update_seo',
    ]);
});

it('registers exactly the eight content tools without it', function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: false));

    expect(mcpToolNames())->toHaveCount(8)
        ->not->toContain('get_seo');
});

it('exposes no tool that can publish or delete, in either state', function () {
    foreach ([true, false] as $available) {
        app()->instance(SeoBridgeContract::class, new FakeSeoBridge($available));

        expect(collect(mcpToolNames())->filter(
            fn (string $n) => str_contains($n, 'publish') || str_contains($n, 'delete')
        ))->toBeEmpty();
    }
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest.bat --no-coverage --filter=TwillContentServer`
Expected: FAIL — SEO tool names missing

- [ ] **Step 3: Write the three wrappers**

Each is one line of real content, matching the existing wrappers:

```php
// src/Mcp/Tools/GetSeo.php
namespace TwillAi\Mcp\Tools;

class GetSeo extends WrappedTwillAiTool
{
    protected function delegateClass(): string
    {
        return \TwillAi\Tools\GetSeo::class;
    }
}
```

Repeat for `AnalyzeSeoText` and `UpdateSeo`, delegating to `\TwillAi\Tools\AnalyzeSeoText::class` and `\TwillAi\Tools\UpdateSeo::class`.

- [ ] **Step 4: Make the server's tool list conditional**

In `TwillContentServer`'s constructor — which already assigns `$this->instructions` — append after that line:

```php
// Appended in the constructor, not the property default, because the SEO
// tools depend on a gate that is only decided at boot.
if (app(\TwillAi\Seo\SeoBridgeContract::class)->available()) {
    $this->tools = array_merge($this->tools, [
        Tools\GetSeo::class,
        Tools\AnalyzeSeoText::class,
        Tools\UpdateSeo::class,
    ]);
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/pest.bat --no-coverage --filter=TwillContentServer`
Expected: PASS

- [ ] **Step 6: Correct the tester-facing document**

`docs/test-plan.md` Test 1.4 currently says **eight** tools and tells testers *"Nine or more is a genuine concern"*. Left alone a tester correctly escalates a non-bug. Replace the expectation and the warning:

```markdown
**What you should see:** **eight** tools if the SEO Suite is not installed, or
**eleven** if it is — the three extra being get_seo, analyze_seo_text and
update_seo.

**✅ PASS if:** exactly eight, or exactly eleven with the SEO Suite installed.

> Ask the developer which of the two applies to the site you are testing.
> **More than that is a genuine concern** — it would mean a tool exists that we
> did not intend to expose. Tell a developer.
```

- [ ] **Step 7: Commit**

```bash
git add src/Mcp tests/ docs/test-plan.md
git commit -m "Expose the SEO tools over MCP and correct the tool-count contract"
```

---

## Phase 5 — Registry, prompts, docs, CI

### Task 10: Registry key and prompt fragment

**Files:**
- Modify: `src/Services/ModuleRegistry.php` (`describe()`), `src/Services/PromptComposer.php`
- Test: `tests/Feature/Seo/SeoPromptAndRegistryTest.php`

**Interfaces:**
- Consumes: `SeoBridgeContract`
- Produces: `describe()['seo']` = `['available' => bool]`; `PromptComposer::seoGuidance(): string`

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Seo/SeoPromptAndRegistryTest.php
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Services\PromptComposer;
use TwillAi\Tests\Fixtures\FakeSeoBridge;

it('reports which modules have an SEO surface', function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: true));

    // Article uses HasSeo; Singleton deliberately does not.
    expect(app(ModuleRegistry::class)->describe('articles')['seo']['available'])->toBeTrue()
        ->and(app(ModuleRegistry::class)->describe('singleton')['seo']['available'])->toBeFalse();
});

it('omits the key entirely without the Suite', function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: false));

    expect(app(ModuleRegistry::class)->describe('articles'))->not->toHaveKey('seo');
});

it('adds SEO guidance to the prompt only when available', function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: true));
    expect(app(PromptComposer::class)->seoGuidance())->toContain('analyze_seo_text');

    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: false));
    expect(app(PromptComposer::class)->seoGuidance())->toBe('');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest.bat --no-coverage --filter=SeoPromptAndRegistry`
Expected: FAIL — undefined key `seo` / undefined method `seoGuidance`

- [ ] **Step 3: Add the registry key**

In `ModuleRegistry::describe()`, before `'notes' =>`:

```php
// Present only when the Suite is installed. A module whose model lacks HasSeo
// has no SEO surface, and the tools must say so rather than fail obscurely.
...(app(\TwillAi\Seo\SeoBridgeContract::class)->available() ? ['seo' => [
    'available' => method_exists($model, 'seoEntry'),
]] : []),
```

- [ ] **Step 4: Add the prompt fragment**

In `PromptComposer`:

```php
/**
 * Describes the SEO loop. Empty without the Suite, so nothing SEO-related ever
 * enters the prompt on a site that does not have it.
 */
public function seoGuidance(): string
{
    if (! app(SeoBridgeContract::class)->available()) {
        return '';
    }

    $generated = <<<'DESC'
    This site has SEO analysis. To improve existing copy: call get_seo to read the
    current score and the failing assessments, draft a rewrite, check it with
    analyze_seo_text BEFORE saving, then write it with update_content and set the
    metadata with update_seo. On a published page always check with
    analyze_seo_text first — never save repeatedly to a live page to watch the
    score move.
    DESC;

    return $this->resolve('seo', $generated);
}
```

Add `use TwillAi\Seo\SeoBridgeContract;` to its imports, and append `seoGuidance()` to the assistant's instructions in `TwillAssistant`.

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/pest.bat --no-coverage --filter=SeoPromptAndRegistry`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Services tests/ src/Agents/TwillAssistant.php
git commit -m "Report SEO availability in the registry and the prompt"
```

---

### Task 11: CI, docs and the full green run

**Files:**
- Modify: `.github/workflows/tests.yml`, `README.md`, `docs/session-handoff.md`
- Modify: `phpunit.xml` (new `Seo` suite)

**Interfaces:**
- Consumes: everything above
- Produces: a CI job proving the package works with the Suite absent

- [ ] **Step 1: Add the Seo testsuite**

`phpunit.xml` suites must stay non-overlapping — PHPUnit warns when a file belongs to two, and `failOnWarning` turns that into a failed run:

```xml
<testsuite name="Seo">
    <directory>tests/Feature/Seo</directory>
</testsuite>
```

- [ ] **Step 2: Add the no-Suite CI job**

In `.github/workflows/tests.yml`, mirroring the existing no-connector job:

```yaml
  test-without-seo:
    runs-on: ubuntu-latest
    name: PHP 8.4 / Laravel 13 / no SEO Suite

    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: mbstring, gd, openssl, sqlite3, pdo_sqlite, zip
          coverage: none

      - name: Install dependencies without the SEO Suite
        run: |
          composer remove --no-update --no-interaction --dev yotech-ai/twill-cms-seo-suite
          composer update --prefer-dist --no-interaction --no-progress

      - name: Run every suite except SEO
        run: vendor/bin/pest --no-coverage --testsuite=Package,TwillAi,Mcp
```

- [ ] **Step 3: Document it in the README**

Add after the Plugins page section:

```markdown
## SEO Suite integration

With [`yotech-ai/twill-cms-seo-suite`](https://packagist.org/packages/yotech-ai/twill-cms-seo-suite) installed, the assistant gains three tools: `get_seo` reads an entry's score and the assessments explaining it, `analyze_seo_text` scores proposed copy without saving anything, and `update_seo` sets the metadata.

Two things it will not do. Indexing controls — `robots_noindex`, `robots_nofollow`, `canonical_url`, `cornerstone` and `schema_type_override` — are refused in code, for the same reason it cannot publish or delete: deindexing a page or reassigning its canonical is quietly destructive. And it never creates published content; new entries are always drafts.

Installing the Suite does change one default: `allow_updating_published` ships as `null`, meaning the assistant may edit **existing published** entries when the Suite is present. Set it to `false` to keep drafts-only. Every edit to a live entry returns `was_published: true` and a warning the assistant is instructed to relay.

Turn the integration off entirely with `TWILL_AI_SEO_ENABLED=false`.
```

- [ ] **Step 4: Run everything**

```bash
vendor/bin/pint.bat
vendor/bin/pest.bat --no-coverage
```

Expected: all green, exit 0.

- [ ] **Step 5: Verify the no-Suite path locally**

Prove the gate before trusting CI, in a throwaway clone so the working tree keeps its dependencies:

```bash
git clone . ../twill-ai-no-seo && cd ../twill-ai-no-seo
composer remove --no-update --no-interaction --dev yotech-ai/twill-cms-seo-suite
composer update --prefer-dist --no-interaction --no-progress
vendor/bin/pest --no-coverage --testsuite=Package,TwillAi,Mcp
```

Expected: green, with no SEO tools registered anywhere.

- [ ] **Step 6: Commit and open the PR**

```bash
git add .github README.md phpunit.xml docs/
git commit -m "Add CI coverage for the SEO Suite being absent, and document the integration"
git push -u origin feature/seo-suite-integration
```

Suggested tag on merge: **v1.2.0** — new capability, no behaviour change on a site without the Suite.

---

## Self-Review

**Spec coverage.** Gate → Task 1. Bridge → Task 2. Three tools → Tasks 3–5. Writable/off-limits → Task 5. Published tri-state → Task 7. Enforced warning → Task 8. Both surfaces → Tasks 6 and 9. Registry and prompt → Task 10. Testing and CI → Tasks 2, 9, 11. Tool-count consequence → Task 9 Step 6. Twill 3.6 floor → Global Constraints and Task 11's job. No gaps.

**Type consistency.** `SeoBridgeContract` methods (`available`, `describe`, `analyzeText`, `updateMeta`) keep the same names and signatures across Tasks 1, 2, 3, 4, 5 and the fake. `SeoFields::WRITABLE`/`OFF_LIMITS` are referenced identically in Tasks 1, 5 and 11. `PublishedEditPolicy::allows()` matches between Tasks 7 and 8.

**Known risk carried into execution.** Task 2's `updateMeta` assumes `seoEntry()->firstOrCreate([])` then `->translations()->firstOrCreate(['locale' => ...])`. That mirrors the Suite's morphOne plus translations shape but is unverified against a live row; if the Suite exposes a dedicated writer service, prefer it and adjust the bridge. Confined to one file by design.
