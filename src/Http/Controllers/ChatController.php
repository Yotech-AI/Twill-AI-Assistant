<?php

namespace TwillAi\Http\Controllers;

use TwillAi\Exceptions\TwillAiException;
use TwillAi\Http\Requests\SendMessageRequest;
use TwillAi\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ChatController extends Controller
{
    public function __construct(protected ChatService $chats) {}

    /**
     * Bootstrap data for the chat UI (page and floating widget).
     */
    public function bootstrap(Request $request): JsonResponse
    {
        $activeChat = null;

        if ($chatId = $request->integer('chat_id')) {
            try {
                $chat = $this->chats->findForUser($chatId, $this->userId());
                $activeChat = [
                    'id' => $chat->id,
                    'title' => $chat->title(),
                    'model_id' => $chat->provider.':'.$chat->model,
                    'status' => $chat->effectiveStatus(),
                ];
            } catch (\Throwable) {
                $activeChat = null;
            }
        }

        return response()->json([
            'title' => config('twill-ai.ui.title', 'Twill AI'),
            'models' => $this->chats->models(),
            'default_model' => $this->chats->defaultModelId(),
            'active_chat' => $activeChat,
        ]);
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'chats' => $this->chats->listForUser($this->userId()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        try {
            $chat = $this->chats->createChat($this->userId(), $validated['model'] ?? null);
        } catch (TwillAiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $chat->id,
            'title' => 'New chat',
            'model_id' => $chat->provider.':'.$chat->model,
            'status' => 'idle',
        ], 201);
    }

    public function show(int $chat): JsonResponse
    {
        $chat = $this->chats->findForUser($chat, $this->userId());

        return response()->json([
            'id' => $chat->id,
            'title' => $chat->title(),
            'model_id' => $chat->provider.':'.$chat->model,
            'status' => $chat->effectiveStatus(),
            'messages' => $this->chats->uiMessages($chat),
        ]);
    }

    public function update(Request $request, int $chat): JsonResponse
    {
        $chat = $this->chats->findForUser($chat, $this->userId());

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        try {
            $chat = $this->chats->updateChat($chat, $validated['title'] ?? null, $validated['model'] ?? null);
        } catch (TwillAiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $chat->id,
            'title' => $chat->title(),
            'model_id' => $chat->provider.':'.$chat->model,
            'status' => $chat->effectiveStatus(),
        ]);
    }

    public function destroy(int $chat): JsonResponse
    {
        $chat = $this->chats->findForUser($chat, $this->userId());

        $this->chats->deleteChat($chat);

        return response()->json(['deleted' => true]);
    }

    /**
     * Queue an agent run for this message. Returns 202 immediately; the UI
     * then polls the events endpoint. Generations run in a queued job so
     * they may take minutes and survive the editor navigating away.
     */
    public function message(SendMessageRequest $request, int $chat): JsonResponse
    {
        $chat = $this->chats->findForUser($chat, $this->userId());

        // A model switch sent along with the message applies from this turn on.
        if ($request->filled('model')) {
            try {
                $chat = $this->chats->updateChat($chat, null, $request->input('model'));
            } catch (TwillAiException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        $validated = $request->validated();

        try {
            $chat = $this->chats->queueMessage(
                $chat,
                $this->userId(),
                (string) ($validated['message'] ?? ''),
                $validated['attachments'] ?? [],
                $validated['mentions'] ?? [],
            );
        } catch (TwillAiException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'queued' => true,
            'chat_id' => $chat->id,
            'status' => $chat->effectiveStatus(),
        ], 202);
    }

    /**
     * Content the agent can be pointed at, for the "@" mention drawer.
     */
    public function mentionables(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', '')) ?: null;

        return response()->json(['items' => $this->chats->mentionables($query)]);
    }

    /**
     * Poll the in-flight turn's buffered events.
     */
    public function events(Request $request, int $chat): JsonResponse
    {
        $chat = $this->chats->findForUser($chat, $this->userId());

        return response()->json(
            $this->chats->eventsAfter($chat, max(0, $request->integer('after')))
        );
    }

    /**
     * Ask the running job to stop. The job acknowledges by writing the
     * terminal turn_complete event.
     */
    public function cancel(int $chat): JsonResponse
    {
        $chat = $this->chats->findForUser($chat, $this->userId());

        $this->chats->requestCancel($chat);

        return response()->json(['cancelling' => true], 202);
    }

    protected function userId(): int
    {
        return (int) auth('twill_users')->id();
    }
}
