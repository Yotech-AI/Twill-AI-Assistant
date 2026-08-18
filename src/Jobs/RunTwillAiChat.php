<?php

namespace TwillAi\Jobs;

use A17\Twill\Models\User as TwillUser;
use TwillAi\Agents\TwillAssistant;
use TwillAi\Models\Chat;
use TwillAi\Models\ChatEvent;
use TwillAi\Models\ChatFile;
use TwillAi\Services\BlockSchemaService;
use TwillAi\Services\ChatAttachmentResolver;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Files\File;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Throwable;

/**
 * Runs one Twill AI agent turn in the background. The HTTP layer only queues
 * this job and polls the chat's event buffer, so generations may take
 * minutes without ever hitting PHP/webserver execution limits — and they
 * survive the editor navigating away mid-stream.
 */
class RunTwillAiChat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Never retry an agent run automatically: it could create duplicate drafts. */
    public int $tries = 1;

    public int $timeout;

    /**
     * @param  array<int, int>  $attachmentIds
     * @param  array<int, array{module: string, id: int}>  $mentions
     */
    public function __construct(
        public int $chatId,
        public int $userId,
        public string $message,
        public array $attachmentIds = [],
        public array $mentions = [],
    ) {
        $this->timeout = (int) config('twill-ai.timeout', 600);

        $this->onConnection(config('twill-ai.queue_connection', 'twill-ai'));
        $this->onQueue(config('twill-ai.queue', 'twill-ai'));
    }

    public function handle(): void
    {
        $chat = Chat::find($this->chatId);
        $user = TwillUser::find($this->userId);

        if (! $chat || ! $user) {
            return;
        }

        $chat->forceFill(['status' => 'streaming'])->save();
        Cache::forget(Chat::cancelCacheKey($chat->id));

        $stream = null;

        try {
            // Re-seed Twill's block registry for this run. A long-running
            // worker consumes it on the first job, so without this every block
            // would read as "Unknown" from the second agent run onward.
            app(BlockSchemaService::class)->ensureRegistered();

            // Use the API key stored on the Settings page for this run (falls
            // back to the env-based config when none is stored).
            app(SettingsService::class)->applyRuntimeConfig($chat->provider);

            $agent = new TwillAssistant($chat);

            $agent = $chat->conversation_id
                ? $agent->continue($chat->conversation_id, as: $user)
                : $agent->forUser($user);

            [$prompt, $attachments] = $this->buildPrompt();

            $stream = $agent
                ->stream($prompt, $attachments)
                ->then(function (StreamedAgentResponse $response) use ($chat) {
                    $chat->forceFill([
                        'conversation_id' => $chat->conversation_id ?? $response->conversationId,
                    ])->save();
                });

            $lastTouch = time();

            foreach ($stream as $event) {
                ChatEvent::record($chat->id, (string) $event);

                // Touch the chat periodically (not per token) so the busy
                // flag never reads as stale during a long generation.
                if (time() - $lastTouch >= 15) {
                    $chat->forceFill(['last_activity_at' => now()])->save();
                    $lastTouch = time();
                }

                if (Cache::pull(Chat::cancelCacheKey($chat->id))) {
                    ChatEvent::record($chat->id, ['type' => 'twill_ai.cancelled']);

                    break;
                }
            }
        } catch (Throwable $e) {
            report($e);

            ChatEvent::record($chat->id, [
                'type' => 'error',
                'message' => 'The assistant run failed: '.$e->getMessage(),
                'recoverable' => false,
            ]);
        } finally {
            $this->finish($chat, $stream?->conversationId);
        }
    }

    /**
     * Invoked by the worker when the job dies hard (e.g. timeout kill).
     */
    public function failed(?Throwable $exception): void
    {
        if (! $chat = Chat::find($this->chatId)) {
            return;
        }

        ChatEvent::record($chat->id, [
            'type' => 'error',
            'message' => 'The assistant run was aborted'.($exception ? ': '.$exception->getMessage() : '.'),
            'recoverable' => false,
        ]);

        $this->finish($chat, null);
    }

    /**
     * Assemble the prompt for this turn: any @-mentioned entries and the text
     * of text-based attachments are prepended as context; image/PDF
     * attachments are returned separately to be sent natively to the model.
     *
     * @return array{0: string, 1: array<int, File>}
     */
    protected function buildPrompt(): array
    {
        $registry = app(ModuleRegistry::class);

        $references = [];

        foreach ($this->mentions as $mention) {
            $module = $mention['module'] ?? null;
            $id = $mention['id'] ?? null;

            if ($module === null || $id === null) {
                continue;
            }

            if ($reference = $registry->entryReference((string) $module, $id)) {
                $references[] = $reference;
            }
        }

        $files = empty($this->attachmentIds)
            ? collect()
            : ChatFile::query()
                ->whereIn('id', $this->attachmentIds)
                ->get();

        ['attachments' => $attachments, 'texts' => $texts] = app(ChatAttachmentResolver::class)->resolve($files);

        $preamble = '';

        if ($references !== []) {
            $lines = array_map(
                fn (array $reference) => "- {$reference['label']} #{$reference['id']}: \"{$reference['title']}\" (module \"{$reference['module']}\")",
                $references
            );

            $preamble .= "The user referenced these existing entries. Use get_content to read them when relevant:\n"
                .implode("\n", $lines)."\n\n";
        }

        $images = $files->filter(fn (ChatFile $file) => $file->isImage());

        if ($images->isNotEmpty()) {
            $imageLines = $images->map(fn (ChatFile $file) => "- file_id {$file->id}: {$file->original_name}")->implode("\n");

            $preamble .= 'Attached image(s) are shown to you above. To place one into a block media role or the '
                .'SEO image, call use_attachment_as_media with its file_id to add it to the Twill media library, then '
                ."use the returned media_id in the relevant \"medias\" payload:\n".$imageLines."\n\n";
        }

        foreach ($texts as $text) {
            $preamble .= "----- Attached file: {$text['name']} -----\n{$text['text']}\n----- end of {$text['name']} -----\n\n";
        }

        $message = trim($this->message);

        if ($message === '') {
            $message = ($attachments !== [] || $texts !== [])
                ? 'Please review the attached file(s).'
                : 'Please look at the content I referenced.';
        }

        return [$preamble.$message, $attachments];
    }

    protected function finish(Chat $chat, ?string $conversationId): void
    {
        $chat->forceFill([
            'status' => 'idle',
            'conversation_id' => $chat->conversation_id ?? $conversationId,
            'last_activity_at' => now(),
        ])->save();

        ChatEvent::record($chat->id, ['type' => 'twill_ai.turn_complete']);
    }
}
