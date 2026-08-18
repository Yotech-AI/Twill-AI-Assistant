<?php

namespace TwillAi\Http\Controllers;

use TwillAi\Exceptions\TwillAiException;
use TwillAi\Http\Requests\SaveApiKeyRequest;
use TwillAi\Http\Requests\UpdateSettingsRequest;
use TwillAi\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Install-wide Twill AI settings (provider, API key, default model, extra
 * system prompt). Available to any authenticated Twill admin. The raw API key
 * is never returned — only a masked form + a "verified" flag.
 */
class SettingsController extends Controller
{
    public function __construct(protected SettingsService $settings) {}

    public function index(): JsonResponse
    {
        return response()->json($this->settings->forDisplay());
    }

    public function storeKey(SaveApiKeyRequest $request): JsonResponse
    {
        try {
            $data = $this->settings->saveApiKey($request->validated('provider'), $request->validated('key'));
        } catch (TwillAiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($data);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        try {
            $data = $this->settings->updateSettings(
                $request->validated('default_model'),
                $request->validated('system_prompt'),
            );
        } catch (TwillAiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($data);
    }

    public function refreshModels(): JsonResponse
    {
        try {
            $data = $this->settings->refreshModels();
        } catch (TwillAiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($data);
    }
}
