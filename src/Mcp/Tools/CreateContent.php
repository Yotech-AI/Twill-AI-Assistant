<?php

namespace TwillAi\Mcp\Tools;

use TwillAi\Mcp\Models\ContentRef;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Tools\CreateContent as CreateContentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\QueryException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Creates a CMS entry as a draft, guarded by a caller-supplied idempotency key.
 *
 * MCP clients retry on timeout, and a multi-locale page with a full block tree
 * legitimately takes minutes, so an un-guarded create would leave duplicate
 * drafts behind whenever a slow-but-successful call was retried. Inside the CMS
 * chat this never mattered because a human watched the result; over MCP nothing
 * is watching.
 *
 * The key is claimed BEFORE the content is written and released again if the
 * write fails, so two concurrent retries cannot both produce an entry — the
 * unique index on external_ref decides the winner.
 *
 * external_ref lives here rather than in TwillAi\Tools\CreateContent on
 * purpose: the in-CMS chat has no use for it, and making it required there
 * would degrade that experience.
 */
class CreateContent extends WrappedTwillAiTool
{
    protected bool $auditable = true;

    protected function delegateClass(): string
    {
        return CreateContentTool::class;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return array_merge(parent::schema($schema), [
            'external_ref' => $schema->string()
                ->description('Stable identifier for this piece of content, unique across all modules. Send the SAME value when retrying a call that timed out — the retry then returns the entry the first attempt created instead of making a second one.')
                ->required(),
        ]);
    }

    public function handle(Request $request): Response
    {
        $reference = trim((string) $request->get('external_ref'));
        $module = (string) $request->get('module');

        if ($reference === '') {
            return Response::error('The "external_ref" argument is required: a stable identifier so a retry cannot create a duplicate.');
        }

        $claim = $this->claim($reference, $module);

        if ($claim instanceof Response) {
            return $claim;
        }

        $result = $this->callDelegate($request);

        $this->audit($request, $result);

        $decoded = json_decode($result, true);

        if (! is_array($decoded) || ($decoded['created'] ?? false) !== true) {
            $claim->delete();

            return $this->toResponse($result);
        }

        $claim->update(['record_id' => $decoded['id'] ?? null]);

        return $this->toResponse($result);
    }

    /**
     * Claim the reference, or answer on behalf of whoever already holds it.
     *
     * Returns a Response when the caller should be answered immediately, and a
     * ContentRef when this call now owns the reference and should go on to
     * create the content.
     */
    protected function claim(string $reference, string $module, int $attempt = 0): ContentRef|Response
    {
        if ($attempt > 1) {
            return Response::error('Could not claim "external_ref" after releasing a stale claim. Retry in a moment.');
        }

        try {
            return ContentRef::create([
                'external_ref' => $reference,
                'module' => $module,
            ]);
        } catch (QueryException) {
            // The unique index rejected us: another call holds this reference.
        }

        $existing = ContentRef::query()->where('external_ref', $reference)->first();

        if ($existing === null) {
            return Response::error('Could not claim "external_ref" and could not read the existing claim. Retry in a moment.');
        }

        if ($existing->module !== $module) {
            return Response::error(sprintf(
                'The external_ref "%s" is already in use by the "%s" module. Choose a different reference — references are unique across all modules.',
                $reference,
                $existing->module,
            ));
        }

        if ($existing->record_id === null) {
            return Response::error('A create for this external_ref is already in progress. Wait for it to finish rather than retrying immediately.');
        }

        if (! $this->targetExists($module, $existing->record_id)) {
            // The entry was deleted by a human; the claim is stale, so release
            // it and let this call create fresh content.
            $existing->delete();

            return $this->claim($reference, $module, $attempt + 1);
        }

        return $this->existingEntryResponse($module, $existing->record_id);
    }

    protected function targetExists(string $module, int $id): bool
    {
        $registry = app(ModuleRegistry::class);

        if (! $registry->has($module)) {
            return false;
        }

        return $registry->modelInstance($module)->newQuery()->whereKey($id)->exists();
    }

    protected function existingEntryResponse(string $module, int $id): Response
    {
        return Response::text((string) json_encode([
            'created' => false,
            'status' => 'draft',
            'module' => $module,
            'id' => $id,
            'edit_url' => app(ModuleRegistry::class)->editUrl($module, $id),
            'note' => 'An entry already exists for this external_ref, so nothing was created.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
