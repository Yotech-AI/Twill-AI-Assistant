<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Twill AI
    |--------------------------------------------------------------------------
    |
    | Configuration for the AI content assistant embedded in the Twill admin.
    | Provider API keys are configured in config/ai.php (the Laravel AI SDK) or,
    | more usually, saved through the assistant's own Settings page.
    |
    */

    'enabled' => env('TWILL_AI_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Models offered in the chat's model picker
    |--------------------------------------------------------------------------
    |
    | This doubles as a server-side whitelist: a chat can only ever use a model
    | listed here. The "id" is what the frontend sends; "provider" must match a
    | provider configured in config/ai.php. Once an API key is saved and
    | verified on the Settings page, that provider's live model list replaces
    | this static one in the picker.
    |
    */

    'default_model' => env('TWILL_AI_DEFAULT_MODEL', 'anthropic:claude-sonnet-4-6'),

    'models' => [
        [
            'id' => 'anthropic:claude-opus-4-8',
            'provider' => 'anthropic',
            'model' => 'claude-opus-4-8',
            'label' => 'Claude Opus 4.8',
            'description' => 'Most capable — big landing pages, complex briefs',
        ],
        [
            'id' => 'anthropic:claude-sonnet-4-6',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
            'label' => 'Claude Sonnet 4.6',
            'description' => 'Fast and smart — recommended default',
        ],
        [
            'id' => 'anthropic:claude-haiku-4-5',
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5',
            'label' => 'Claude Haiku 4.5',
            'description' => 'Fastest — small edits and quick questions',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers offered on the Settings page
    |--------------------------------------------------------------------------
    |
    | provider key (must match config/ai.php) => display label.
    |
    */

    'providers' => [
        'anthropic' => 'Anthropic',
        'openai' => 'OpenAI',
        'gemini' => 'Google Gemini',
        'mistral' => 'Mistral',
    ],

    /*
    |--------------------------------------------------------------------------
    | Module registry — THE ONE THING YOU MUST FILL IN
    |--------------------------------------------------------------------------
    |
    | ONLY modules listed here are reachable by the AI agent. Everything else in
    | your CMS (users, orders, application data) simply does not exist from the
    | agent's viewpoint. An empty registry is safe: the assistant will run and
    | answer questions, but has no content it may read or write.
    |
    | Per module:
    | - label / description: shown to the model. The description is the single
    |   most effective place to teach it how a module should be written.
    | - model / repository: the Twill module classes.
    | - route: the Twill route name segment (as passed to TwillRoutes::module()).
    | - singleton: true for singleton modules (update-only, never create).
    | - operations: subset of read|create|update. There is NO delete operation
    |   anywhere in Twill AI, by design, and no publish.
    | - block_editors: editor name => allowed block names, mirroring getForm().
    |   A controller calling BlockEditor::make() without a name gets the editor
    |   called "default" — the only one HasBlocks::renderBlocks() reads.
    | - browsers: Twill "related browsers" (saved through twill_related).
    | - sync_fields: plain belongsToMany id-array fields synced in afterSave().
    | - extra_fields: whitelisted NON-translated columns the agent may set.
    |   Anything not listed here is stripped from agent payloads. Needed for
    |   models that do not use HasTranslation.
    |
    | A worked example — delete the comment markers and adapt:
    |
    | 'pages' => [
    |     'label' => 'Pages',
    |     'description' => 'Standard site pages, served from /{slug}. Written entirely in the block editor.',
    |     'model' => App\Models\Page::class,
    |     'repository' => App\Repositories\PageRepository::class,
    |     'route' => 'pages',
    |     'operations' => ['read', 'create', 'update'],
    |     'block_editors' => [
    |         'default' => ['content-hero', 'content-text', 'content-faq'],
    |     ],
    |     'browsers' => [],
    |     'sync_fields' => [],
    |     'extra_fields' => [],
    | ],
    |
    | 'homepage' => [
    |     'label' => 'Homepage',
    |     'description' => 'The site homepage (singleton — can only be updated).',
    |     'model' => App\Models\Homepage::class,
    |     'repository' => App\Repositories\HomepageRepository::class,
    |     'route' => 'homepage',
    |     'singleton' => true,
    |     'operations' => ['read', 'update'],
    |     'block_editors' => ['default' => ['content-hero', 'content-text']],
    |     'extra_fields' => [
    |         'title' => 'string',
    |         'seo_description' => 'string',
    |     ],
    | ],
    |
    */

    'modules' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Safety & limits
    |--------------------------------------------------------------------------
    |
    | The agent can never publish and can never delete — those are product
    | guarantees enforced in code, not settings. The switch below only controls
    | whether it may UPDATE entries a human already published (it still cannot
    | change their publish state). Default: drafts only.
    |
    */

    'allow_updating_published' => false,

    'max_blocks_per_request' => 30,

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Agent runs execute in a queued job so they can take minutes without
    | hitting PHP/webserver execution limits. The package registers this
    | connection automatically (database driver, retry_after above the timeout)
    | unless you define one yourself. A worker must process it:
    |
    |   php artisan queue:work twill-ai --queue=twill-ai --timeout=620
    |
    | NOTE: queue:work caches code in memory — restart it after any code change
    | (php artisan queue:restart) or the agent runs stale tools. In development
    | prefer queue:listen, which reloads code per job.
    |
    | The MCP endpoint does NOT use this queue: an external client is already
    | the model doing the writing, so its tool calls run inline in the request.
    |
    */

    'queue_connection' => 'twill-ai',
    'queue' => 'twill-ai',

    // Job timeout AND per-call HTTP timeout (seconds) for the provider.
    'timeout' => (int) env('TWILL_AI_TIMEOUT', 600),

    // Locales the agent may write. null = config('translatable.locales').
    'locales' => null,

    /*
    |--------------------------------------------------------------------------
    | File uploads
    |--------------------------------------------------------------------------
    |
    | Files attached in the chat are stored on a PRIVATE disk with no public URL
    | that is never symlinked into public/. The package registers this disk
    | automatically unless you define one yourself. Images and PDFs are sent to
    | the model natively; text files (txt/md/csv/docx) are flattened to text and
    | injected as context (docx requires phpoffice/phpword).
    |
    */

    'uploads' => [
        'disk' => 'twill-ai',
        'max_kb' => 20480,
        'max_files_per_message' => 5,
        'max_text_chars' => 50000,
        'extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'txt', 'md', 'csv', 'docx'],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP connector (optional)
    |--------------------------------------------------------------------------
    |
    | Exposes the same content tools to external MCP clients such as Claude.
    | Requires laravel/mcp and laravel/passport; the connector stays dormant
    | unless BOTH this flag is true and laravel/mcp is installed, so a site can
    | run the assistant with no OAuth stack at all.
    |
    | After enabling: php artisan passport:keys, set passport.guard to
    | twill_users, then php artisan mcp:client-create to authorise a connector.
    | Run php artisan twill-ai:doctor to check all of that at once.
    |
    */

    'mcp' => [
        'enabled' => env('TWILL_AI_MCP_ENABLED', false),

        // HTTP path for the remote server, and the handle used by
        // `php artisan mcp:inspector <handle>` for the local stdio server.
        'path' => 'mcp/twill',
        'local_handle' => 'twill-content',

        // Rate limit for the remote endpoint, matching the in-admin chat.
        'throttle' => '30,1',
    ],

    /*
    |--------------------------------------------------------------------------
    | UI
    |--------------------------------------------------------------------------
    */

    'ui' => [
        'title' => 'Twill AI',
        'empty_intro' => "Your AI content assistant. Tell me what you'd like to create or change and I'll draft it for you to review.",
        'empty_hint' => null,
    ],

    'floating_widget' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt text
    |--------------------------------------------------------------------------
    |
    | The assistant derives its worked examples from the module registry above,
    | so the prompts describe YOUR blocks and locales without any editing. These
    | keys are the escape hatches.
    |
    | site_description: one line naming what this site is. Given to external MCP
    | clients, which have no other context. e.g. 'a focus-timer and workout app'.
    |
    | prompts.*: override a generated prompt fragment outright. Leave null to
    | use the generated text. prompts.append is added to the agent's system
    | prompt after everything else.
    |
    */

    'site_description' => env('TWILL_AI_SITE_DESCRIPTION'),

    'prompts' => [
        'create_content' => null,
        'list_blocks_editor' => null,
        'search_content_relations' => null,
        'assistant_instructions' => null,
        'mcp_instructions' => null,
        'append' => '',
    ],

    // Appended verbatim to the agent's system prompt. Use it for project
    // specific tone-of-voice or content rules.
    'system_prompt_additions' => '',
];
