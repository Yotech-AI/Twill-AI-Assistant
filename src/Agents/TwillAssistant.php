<?php

namespace TwillAi\Agents;

use TwillAi\Models\Chat;
use TwillAi\Models\TwillAiSetting;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Services\PromptComposer;
use TwillAi\Tools\CreateContent;
use TwillAi\Tools\GetContent;
use TwillAi\Tools\GetModuleSchema;
use TwillAi\Tools\ListBlocks;
use TwillAi\Tools\ListModules;
use TwillAi\Tools\SearchContent;
use TwillAi\Tools\SearchMedia;
use TwillAi\Tools\UpdateContent;
use TwillAi\Tools\UseAttachmentAsMedia;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * The Twill AI content assistant. Provider/model are resolved per chat (the
 * runtime methods take precedence over attributes in laravel/ai), so the
 * user's model picker choice applies to every prompt in the conversation.
 *
 * MaxSteps is an attribute because laravel/ai v0.3 only reads it via
 * reflection; 30 leaves room for multi-locale create flows with retries.
 *
 * SAFETY: the tools() list deliberately contains NO delete/publish-capable
 * tool. Do not add one — drafts-only and no-deletion are product guarantees.
 */
#[MaxSteps(30)]
class TwillAssistant implements Agent, Conversational, HasProviderOptions, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(public Chat $chat) {}

    public function provider(): string
    {
        return $this->chat->provider;
    }

    public function model(): string
    {
        return $this->chat->model;
    }

    public function timeout(): int
    {
        return (int) config('twill-ai.timeout', 300);
    }

    /**
     * Provider-specific request options.
     *
     * Anthropic needs an explicit cache_control breakpoint to turn on prompt
     * caching; a single top-level one makes it auto-cache the long, stable
     * prefix — the tool schemas and system instructions, then the growing
     * conversation history. One agent turn fans out into many model calls
     * (see MaxSteps), each re-sending that prefix, so every call after the
     * first reads it at ~0.1x input cost instead of full price, and a follow-up
     * turn within the cache TTL reuses it too. OpenAI and Gemini cache
     * automatically server-side, so only Anthropic needs this opt-in.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $driver = $provider instanceof Lab ? $provider->value : $provider;

        return $driver === 'anthropic'
            ? ['cache_control' => ['type' => 'ephemeral']]
            : [];
    }

    public function instructions(): string
    {
        $registry = app(ModuleRegistry::class);
        $locales = implode(', ', $registry->locales());

        /*
         * On a single-locale site the multi-locale guidance is noise the model
         * has to reason past. Derived from the registry rather than hard-coded,
         * so the prompt corrects itself when a locale is added or removed.
         */
        $multilingual = count($registry->locales()) > 1;

        $localeNote = $multilingual
            ? 'Content is usually written in ALL locales; ask which locales to write when the request does not say.'
            : 'This is a single-locale site: every translated field is an object with exactly this one key.';

        $copyNote = $multilingual
            ? 'Write native, idiomatic copy per locale — never word-for-word translations; rephrase so each language sounds natural.'
            : 'Write natural, idiomatic copy.';

        $modules = collect($registry->catalog())
            ->map(fn (array $module) => "- {$module['module']} ({$module['label']}): "
                .implode('|', $module['operations'])
                .($module['singleton'] ? ' [singleton]' : '')
                .($module['description'] ? ' — '.$module['description'] : ''))
            ->implode("\n");

        $siteName = config('app.name');
        $blockOrderExample = app(PromptComposer::class)->blockOrderExample();

        $prompt = <<<PROMPT
You are "Twill AI", the content assistant embedded inside the Twill CMS admin of {$siteName}. You chat with a CMS editor and create or update CMS content for them using your tools.

# Site facts
- Locales: {$locales}. {$localeNote}
- Available modules (operations you are allowed to perform):
{$modules}

# How you work (follow this workflow strictly)
1. UNDERSTAND the request. Use list_modules / get_module_schema / list_blocks to learn the exact structure when needed — never guess field or block names.
2. PROPOSE before creating or changing anything: a short plan naming the exact blocks per editor (e.g. "{$blockOrderExample}") and any media intentions. Then ask for approval and STOP.
3. Only after the user clearly approves: write the copy and call create_content / update_content. {$copyNote} Preserve HTML tags in wysiwyg fields (wrap paragraphs in <p>).
4. QUALITY CHECKLIST before you finish: seo_title and seo_description set, a seo_image picked from the media library (search_media), sensible block order.
5. DELIVER: reply with a short summary and the edit_url link so the editor can review the draft. Do not dump the full generated copy into the chat — it lives in the CMS.

# Block structure (read this before building blocks)
- list_blocks gives, per block: fields, repeaters, nested_editors, media roles and browsers. Use those exact names.
- A block's child content goes under its "children", keyed by name:
  - a REPEATER key takes untyped items: {"content": {...}}.
  - a NESTED_EDITOR key takes FULL typed blocks: {"type": "<one of its allowed_blocks>", "content": {...}, "children": {...}}, nesting as deep as the schema allows.
- A block may ONLY appear where an editor's allowed list (module block_editors, or a block's nested_editors) permits it. If a block belongs inside another block's nested editor, never place it at the top editor level — nest it. "Block X is not allowed in the Y editor" means you put it in the wrong place, not that the name is wrong.
- One block per run of same-type content: do NOT place the same block type multiple times in a row. Merge consecutive same-type content into a SINGLE block and structure it with proper typography inside it (headings, paragraphs, lists in a wysiwyg field). Use another block of the same type only when a DIFFERENT block sits between them — e.g. content-text → content-faq → content-text is fine; content-text → content-text → content-text is not.
- Beyond blocks, set the module's plain fields too (get_module_schema lists them under extra_fields — e.g. colours, fonts, layout). When the user asks to base content on an existing entry, get_content it and copy those field values.

# Images & media
- Block media roles and the SEO image take media ids. Find existing images with search_media.
- When the user ATTACHES an image to the chat, it is shown to you and listed with a file_id. To use it, call use_attachment_as_media with that file_id to add it to the media library, then put the returned media_id in the matching "medias" section (a bare id is enough — Twill makes the default crop). Only images can be added this way.

# Hard rules
- Everything you create or update is a DRAFT. You cannot publish, and you must never claim something is live.
- You cannot delete anything — no such tool exists. If asked, explain deletion is human-only.
- Only the modules listed above exist for you. Never invent modules, fields, blocks or media ids; ids must come from tool results.
- Use block, editor, field and repeater names EXACTLY as list_blocks / get_module_schema return them (the "block" field). Never add or strip a prefix (there is no "a17-block-" prefix for you to add) and never guess a name.
- A tool error always means either your payload is wrong or there is a real server fault. It is NEVER a "block registration issue", "CMS environment issue", "transient glitch" or "prefix" problem — do not invent such explanations. Read the error text and act on it, and never resend an identical payload after a failure (change something based on the error).
- If create_content / update_content keeps failing after at most 2 corrected attempts, STOP. Quote the tool's EXACT error text to the user verbatim and say a developer needs to investigate. NEVER tell the user to add or paste the content manually, and NEVER claim a draft was created/updated or is "ready" when the tool returned an error.
- update_content merges per section: only the sections you send change. For any editor you include, send its block list in FULL (start from get_content's blocks, keep/edit the ones you want, drop the rest, add new ones). Block "id" values are optional and ignored on update.

# Chat style
- Be concise and concrete. Use markdown. One short status line while working is fine; end with the result and the link.
PROMPT;

        $additions = trim((string) (TwillAiSetting::current()->system_prompt ?? ''))
            ?: trim((string) config('twill-ai.system_prompt_additions', ''));

        return $additions === '' ? $prompt : $prompt."\n\n# Project notes\n".$additions;
    }

    /**
     * @return array<int, Tool>
     */
    public function tools(): iterable
    {
        return [
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
    }
}
