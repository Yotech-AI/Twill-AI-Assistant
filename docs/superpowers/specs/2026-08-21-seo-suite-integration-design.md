# Twill AI × SEO Suite integration — design

**Status:** approved in chat, not yet implemented
**Date:** 2026-08-21
**Package:** `yotech-ai/twill-cms-ai-assistant`
**Integrates:** `yotech-ai/twill-cms-seo-suite` (v1.0.0, on Packagist)

## Purpose

Let an editor use the assistant to improve copy that already exists, guided by
the SEO Suite's real scoring rather than the model's guesses about SEO.

The job to be done, in one sentence: *"read what this page scores and why, rewrite
it until it scores better, and set the metadata to match."*

## Decisions

Taken in conversation, recorded here so the reasoning survives.

| Decision | Choice | Why |
|---|---|---|
| Depth of SEO reach | Read scores, write meta, re-analyse | The full loop. Re-analysis is pure computation, so it buys capability without adding write risk. |
| Surfaces | Admin chat **and** MCP connector | Bulk SEO work over Cowork/Desktop is a real use case. Consequence: the connector's tool count changes — see Consequences. |
| Published-entry edits | Auto-enabled when the Suite is installed | User's call, made after the downside was named. Mitigated below. |
| New entries | Always draft, never configurable | Already guaranteed in code; unchanged by this work. |
| "Editing live content" warning | Enforced in the tool result | The package's safety guarantees live in code, not prompts. A prompt-only warning is lost the moment a host overrides `twill-ai.prompts.*`. |
| Integration shape | Optional dependency + one adapter seam | Mirrors the proven MCP gate. No changes needed in the SEO Suite. |

## The gate

Identical in shape to the existing MCP gate, for the same reason: a host that
never installed the Suite must not see the package try.

```php
protected function seoAvailable(): bool
{
    return (bool) config('twill-ai.seo.enabled', true)
        && class_exists(\TwillSeo\Analysis\AnalysisRunner::class);
}
```

Note the default is `true`, unlike `mcp.enabled`. The connector is a deliberate
opt-in because it opens a network surface; SEO is inert unless the Suite is
present, so `class_exists` alone is a sufficient guard and a host that installed
the Suite plainly wants it used.

**Both halves are evaluated in `boot()`**, together, for the reason the MCP guard
had to be fixed: a gate evaluated in two lifecycle phases can disagree, and the
failure mode is tools registered against services that were never bound.

## The bridge

`TwillAi\Seo\SeoBridge` is the **only** class in this package permitted to import
`TwillSeo\*`. Everything else — tools, prompts, tests — talks to the bridge.

```php
interface SeoBridgeContract
{
    public function available(): bool;

    /** Meta + a freshly computed report for a saved entry. */
    public function describe(TwillModelContract $entry, string $locale): array;

    /** Score arbitrary text. No entry, no persistence. */
    public function analyzeText(array $paper): array;

    /** Write whitelisted meta fields. Returns what changed. */
    public function updateMeta(TwillModelContract $entry, string $locale, array $fields): array;
}
```

Two things this buys:

- A Suite refactor breaks **one file**, not six.
- The package's own suite runs against a fake bridge, so tests do not need the
  Suite booted to cover tool behaviour. The real bridge gets its own tests
  against the real Suite.

If a third party ever needs this, the bridge is exactly the thing that becomes a
published interface. Not building that now — one consumer.

## Tool surface

Three tools, registered on both surfaces when the gate is open.

### `get_seo` (read-only)

Arguments: `module`, `id`, `locale?`

Returns the entry's stored meta plus a **freshly computed** `AnalysisReport`:
`locale`, `languageSupported`, `seo` and `readability` (each `score`, `rating`,
`results[]`), and `insights`. The per-assessment `results` carry the guidance text
explaining *why* something failed — that text is the point of the tool, not the
number.

### `analyze_seo_text` (read-only, pure)

Arguments: `text`, `keyphrase`, `title?`, `description?`, `slug?`, `locale?`

Maps to `TwillSeo\Analysis\Paper\Paper`. No entry, no database, no writes.

This is what makes improving a **published** page safe: the agent iterates on
proposed copy in memory and writes once, rather than saving repeatedly to a live
page to watch the score move.

### `update_seo` (write)

Arguments: `module`, `id`, `locale`, `fields`

Writes SEO meta only. Body content stays with `update_content` — different data,
different storage (`SeoEntryTranslation` via `HasSeo`), different permission
story. Merging them would change `update_content`'s existing payload contract for
no benefit.

## Writable vs off-limits meta

The Suite's meta table mixes editorial copy with fields that change how search
engines treat the page. Only the copy is writable.

**Writable:** `seo_title`, `seo_description`, `focus_keyphrase`, `og_title`,
`og_description`, `twitter_title`, `twitter_description`.

**Off-limits, enforced in code:** `robots_noindex`, `robots_nofollow`,
`canonical_url`, `cornerstone`, `schema_type_override`.

The reasoning is the same one that stops the agent publishing or deleting.
`robots_noindex` removes a page from search results; `canonical_url` hands its
ranking signals to a different URL. Both are quietly destructive, neither is
obviously so at the moment of the call, and both are trivially reachable from a
plausible instruction like *"stop this page competing with the new one."* They
are human decisions.

An unknown or off-limits key is **rejected with a naming error**, not silently
dropped — consistent with `PayloadBuilder`'s existing "Unknown field" behaviour.

## Published entries and the enforced warning

`allow_updating_published` keeps its meaning — *may the agent edit an entry a
human already published* — but gains a third state. The shipped default changes
from `false` to `null`:

```php
// null  = decide from context: permitted only when the SEO Suite is installed
// true  = always permitted
// false = never permitted
'allow_updating_published' => null,
```

Resolution: `null` means permitted **only** when the gate is open. Without the
Suite, `null` refuses exactly as `false` does today, so the effective behaviour on
every existing site is unchanged.

This is not a breaking change. A host that published `config/twill-ai.php` has a
literal `false` in their file and keeps refusing until they choose otherwise. Only
a host that never published the config, *and* installs the Suite, sees the new
permission — which is precisely the requested auto-enable.

The escape hatch matters: a site that installed the Suite purely for sitemaps sets
`false` and is done. Implicit coupling without that hatch would have taken the
decision away from them.

**The warning is a property of the tool result, not of the prose.** Both
`update_content` and `update_seo`, when the target was published and live, return
a result carrying:

```json
{ "updated": true, "was_published": true,
  "warning": "This entry is PUBLISHED and live. The change is now visible to visitors." }
```

Machine-readable, so it survives over MCP where there is no UI, and the agent
cannot answer without having seen it. The system prompt additionally instructs the
agent to surface it, but the prompt is reinforcement, not the mechanism.

Creating remains draft-only and unconditional. `buildForCreate` forces
`published = false` with no config path, and that does not change.

## Registry and prompts

`ModuleRegistry::describe()` gains a `seo` key when the gate is open, reporting
whether the module's model uses `HasSeo`. A module whose model lacks the trait has
no SEO surface, and the tools must say so rather than fail obscurely.

`PromptComposer` gains one fragment describing the SEO loop, generated only when
the gate is open, overridable through `twill-ai.prompts.seo` like every other
fragment. Nothing SEO-related enters the prompt on a site without the Suite.

## Testing

Fixture work lands in the existing `tests/Fixtures` CMS:

- `Article` gains `HasSeo`, giving a module with SEO.
- `Singleton` deliberately does **not**, giving a module without one — the
  "module has no SEO surface" path needs a real subject.

Coverage:

1. **Gate** — no SEO tools, no prompt fragment, no registry key when the Suite is
   absent; the assistant otherwise fully working.
2. **Bridge** — against the real Suite, `require-dev`'d at `^1.0`.
3. **Tools** — against a fake bridge, so tool behaviour is testable without
   booting the Suite.
4. **Off-limits fields** — every one of the five rejected by name.
5. **Published flow** — flag `false` refuses; flag `null` + Suite permits and
   returns `was_published` with the warning; flag `null` without the Suite
   refuses; creating is draft-only in all four combinations.
6. **MCP** — the connector exposes the tools, and `TwillContentServerTest`'s
   count assertion is updated deliberately rather than incidentally.

CI gains a job with the Suite **absent**, mirroring the existing no-connector job.
That is the only way to exercise the `class_exists` half of the gate.

## Consequences

**The connector goes from 8 tools to 11.** Three things say "eight" today and
each must change deliberately:

- `TwillContentServerTest` — the count and the name list.
- `docs/test-plan.md` Test 1.4 — currently instructs testers that **"Nine or more
  is a genuine concern"**. Left alone, a tester correctly escalates a non-bug.
- The MCP server's `instructions` string, which enumerates the workflow.

**Twill 3.6 becomes the floor for the SEO path.** The Suite requires
`area17/twill: ^3.6`; this package declares `^3.5`. The declaration does not
change — a 3.5 host simply never opens the gate — but the CI matrix must not try
to install the Suite on a 3.5 resolution.

**Version:** minor. New capability, no existing behaviour altered on a site
without the Suite.

## Out of scope

- Bulk or site-wide SEO audit tooling. Heavy to hang off an external connector,
  and not what was asked for.
- Sitemap, robots and schema configuration. Site settings, not content.
- Letting the agent publish, unpublish or delete. Unchanged and non-negotiable.
- Promoting the bridge to a shared published interface. One consumer; revisit if a
  third party appears.
