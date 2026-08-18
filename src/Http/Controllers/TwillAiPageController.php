<?php

namespace TwillAi\Http\Controllers;

use TwillAi\Services\ChatService;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

class TwillAiPageController extends Controller
{
    public function index(): View
    {
        return view('twill-ai::chat', [
            'bootstrap' => self::clientConfig('page'),
        ]);
    }

    /**
     * Config injected into the Vue mount (page + floating widget). Kept in one
     * place so both surfaces stay in sync. Empty-state text is configurable so
     * the drop-in reads sensibly in any Twill project.
     *
     * @return array<string, mixed>
     */
    public static function clientConfig(string $mode): array
    {
        $chats = app(ChatService::class);

        return [
            'mode' => $mode,
            'title' => config('twill-ai.ui.title', 'Twill AI'),
            'intro' => config('twill-ai.ui.empty_intro'),
            'hint' => config('twill-ai.ui.empty_hint'),
            'csrf' => csrf_token(),
            'models' => $chats->models(),
            'default_model' => $chats->defaultModelId(),
            'uploads' => [
                'max_kb' => (int) config('twill-ai.uploads.max_kb', 20480),
                'max_files' => (int) config('twill-ai.uploads.max_files_per_message', 5),
                'extensions' => array_values(config('twill-ai.uploads.extensions', [])),
            ],
            'urls' => self::urls(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function urls(): array
    {
        $prefix = config('twill.admin_route_name_prefix', 'twill.').'ai.';

        return [
            'page' => route($prefix.'index'),
            'bootstrap' => route($prefix.'bootstrap'),
            'chats' => route($prefix.'chats.index'),
            'mentionables' => route($prefix.'mentionables'),
            'files' => route($prefix.'files.index'),
            'settings' => route($prefix.'settings.index'),
        ];
    }
}
