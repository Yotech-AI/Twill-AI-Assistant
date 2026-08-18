<?php

namespace TwillAi\Tools\Concerns;

use TwillAi\Exceptions\TwillAiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

trait HandlesToolErrors
{
    /**
     * Run a tool body and convert failures into an error string the model can
     * read and self-correct from, instead of breaking the stream.
     */
    protected function guard(callable $callback): string
    {
        try {
            $result = $callback();

            return is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (TwillAiException $e) {
            return json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (ValidationException $e) {
            return json_encode([
                'error' => 'Twill validation failed. Fix these and retry:',
                'details' => $e->errors(),
            ], JSON_UNESCAPED_UNICODE);
        } catch (ModelNotFoundException) {
            return json_encode(['error' => 'Entry not found.']);
        } catch (Throwable $e) {
            Log::error('[twill-ai] tool failure: '.$e->getMessage(), ['exception' => $e]);

            return json_encode([
                'error' => 'Unexpected server error ('.class_basename($e).'): '.$e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Decode a tool argument that should be a JSON object. Models pass it
     * either as a JSON string OR as an actual object (which the SDK hands us
     * as an array), so accept both rather than crashing on the type.
     *
     * @return array<string, mixed>
     */
    protected function decodeJsonArgument(mixed $json, string $argument): array
    {
        // Already an object/array (the model sent it structured): use as-is.
        if (is_array($json)) {
            return $json;
        }

        if (! is_string($json) || trim($json) === '') {
            throw new TwillAiException("The \"{$argument}\" argument is required (a JSON object).");
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new TwillAiException("The \"{$argument}\" argument is not valid JSON: ".json_last_error_msg());
        }

        return $decoded;
    }
}
