<?php

namespace TwillAi\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool as AiTool;
use Laravel\Ai\Tools\Request as AiRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Base adapter that exposes an existing Twill AI tool over MCP.
 *
 * The classes in TwillAi\Tools already carry the name, description and
 * input schema a model needs, and both laravel/ai and laravel/mcp build their
 * schemas on Illuminate\Contracts\JsonSchema\JsonSchema — so the schema ports
 * across untouched and each subclass here is a single line.
 *
 * SAFETY: this layer adds no capability of its own. Content created through it
 * is a draft because PayloadBuilder forces it, and nothing can be published or
 * deleted because no such tool exists to wrap. Do not add one.
 */
abstract class WrappedTwillAiTool extends Tool
{
    protected ?AiTool $resolved = null;

    /**
     * Whether calls to this tool are written to the log. Set on tools that
     * change content, so every MCP write is traceable to a client.
     */
    protected bool $auditable = false;

    /**
     * The Twill AI tool class this MCP tool delegates to.
     *
     * @return class-string<AiTool>
     */
    abstract protected function delegateClass(): string;

    public function name(): string
    {
        return $this->delegate()->name();
    }

    public function description(): string
    {
        return (string) $this->delegate()->description();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->delegate()->schema($schema);
    }

    public function handle(Request $request): Response
    {
        $result = $this->callDelegate($request);

        $this->audit($request, $result);

        return $this->toResponse($result);
    }

    /**
     * Record a content-changing call so an MCP write can always be traced back
     * to the connector that made it. Reads are not logged — they are frequent
     * and carry no accountability weight.
     *
     * The connector comes from ActAsTwillUser, not from $request->user(): under
     * OAuth the authenticated user is the CMS admin who approved the connector,
     * and logging them would credit a person for a machine's write. This log is
     * currently the only record of who wrote what, because no module in this
     * application enables Twill revisions.
     */
    protected function audit(Request $request, string $result): void
    {
        if (! $this->auditable) {
            return;
        }

        $decoded = json_decode($result, true);
        $client = app()->bound('mcp.client') ? app('mcp.client') : null;

        Log::info('[mcp] '.$this->name(), [
            'client_id' => $client?->getKey(),
            'client' => $client?->name,
            'module' => $request->get('module'),
            'entry_id' => is_array($decoded) ? ($decoded['id'] ?? null) : null,
            'outcome' => is_array($decoded) && isset($decoded['error']) ? 'error' : 'ok',
        ]);
    }

    /**
     * Run the underlying tool and return its raw JSON string, so subclasses can
     * inspect the outcome before it is turned into a response.
     */
    protected function callDelegate(Request $request): string
    {
        return (string) $this->delegate()->handle(
            new AiRequest($request->all())
        );
    }

    protected function toResponse(string $result): Response
    {
        $error = $this->errorFrom($result);

        return $error === null
            ? Response::text($result)
            : Response::error($error);
    }

    protected function delegate(): AiTool
    {
        return $this->resolved ??= app($this->delegateClass());
    }

    /**
     * Twill AI tools never throw — HandlesToolErrors::guard() converts every
     * failure into a JSON string shaped {"error": ..., "details"?: ...}. Left
     * untranslated, an MCP client would read a failure as a successful call,
     * so the error key is mapped back onto a real MCP error response.
     */
    protected function errorFrom(string $result): ?string
    {
        $decoded = json_decode($result, true);

        if (! is_array($decoded) || ! isset($decoded['error'])) {
            return null;
        }

        $message = (string) $decoded['error'];

        if (isset($decoded['details'])) {
            $message .= ' '.json_encode($decoded['details'], JSON_UNESCAPED_UNICODE);
        }

        return $message;
    }
}
