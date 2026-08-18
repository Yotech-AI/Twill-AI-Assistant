<?php

namespace TwillAi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TwillAi\Http\Requests\UploadFileRequest;
use TwillAi\Services\ChatService;

/**
 * The shared Twill AI file library (the Uploads page + the composer "+"). Files
 * live on the private "twill-ai" disk — no public URL, never symlinked. Every
 * admin sees the same library; a file is readable only through the streamed,
 * authenticated show route below.
 */
class FileController extends Controller
{
    public function __construct(protected ChatService $chats) {}

    public function index(): JsonResponse
    {
        return response()->json(['files' => $this->chats->listFiles()]);
    }

    public function store(UploadFileRequest $request): JsonResponse
    {
        $stored = $this->chats->storeLibraryUploads($this->userId(), $request->file('files', []));

        return response()->json(['files' => $stored], 201);
    }

    public function show(int $file): StreamedResponse
    {
        $record = $this->chats->findFile($file);

        return Storage::disk($record->disk)->response(
            $record->path,
            $record->original_name,
            [
                'Content-Type' => $record->mime ?: 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=0, no-store',
            ],
            $record->isImage() ? 'inline' : 'attachment',
        );
    }

    public function destroy(int $file): JsonResponse
    {
        $this->chats->deleteChatFile($this->chats->findFile($file));

        return response()->json(['deleted' => true]);
    }

    protected function userId(): int
    {
        return (int) auth('twill_users')->id();
    }
}
