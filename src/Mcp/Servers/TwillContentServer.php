<?php

namespace TwillAi\Mcp\Servers;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;
use ReflectionProperty;
use TwillAi\Mcp\Tools\AnalyzeSeoText;
use TwillAi\Mcp\Tools\CreateContent;
use TwillAi\Mcp\Tools\GetContent;
use TwillAi\Mcp\Tools\GetModuleSchema;
use TwillAi\Mcp\Tools\GetSeo;
use TwillAi\Mcp\Tools\ListBlocks;
use TwillAi\Mcp\Tools\ListModules;
use TwillAi\Mcp\Tools\SearchContent;
use TwillAi\Mcp\Tools\SearchMedia;
use TwillAi\Mcp\Tools\UpdateContent;
use TwillAi\Mcp\Tools\UpdateSeo;
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Services\PromptComposer;

/**
 * Exposes the Twill CMS write surface to external MCP clients (Claude Cowork).
 *
 * This server calls the tools in TwillAi\Tools DIRECTLY and deliberately
 * does not route through TwillAi\Agents\TwillAssistant. The connecting
 * client is already the model doing the writing; going through the in-CMS
 * agent would be a model driving a model — double the cost and latency, with
 * two sets of instructions competing. TwillAssistant continues to serve the
 * in-admin chat, untouched.
 *
 * SAFETY: the tool list below contains no publish or delete capability, and
 * none exists to add. Drafts-only is a product guarantee — see PayloadBuilder.
 */
class TwillContentServer extends Server
{
    protected string $name = 'Twill Content';

    protected string $version = '0.1.0';

    /**
     * @var array<int, class-string>
     */
    protected array $tools = [
        ListModules::class,
        GetModuleSchema::class,
        ListBlocks::class,
        SearchContent::class,
        GetContent::class,
        SearchMedia::class,
        CreateContent::class,
        UpdateContent::class,
    ];

    protected string $instructions = '';

    /**
     * The instructions describe the connected site's own modules, locales and
     * quirks, so they are composed at build time rather than hard-coded.
     * Laravel\Mcp\Server reads $this->instructions when it builds its response,
     * after construction, so assigning here works on both 0.5.x and 0.9.x.
     */
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);

        $this->instructions = app(PromptComposer::class)->mcpInstructions();

        $this->tools = static::effectiveTools();
    }

    /**
     * The tools this server actually exposes right now.
     *
     * Separate from the $tools default because the SEO gate is only decided at
     * boot, and because `mcp:doctor` needs the same answer WITHOUT constructing
     * a server — it has no Transport. It used to read the property default by
     * reflection, so it reported eight tools on a site exposing eleven and
     * never resolve-checked the three it could not see.
     *
     * @return array<int, class-string>
     */
    public static function effectiveTools(): array
    {
        $tools = (new ReflectionProperty(static::class, 'tools'))->getDefaultValue();

        // A connector on a site without the Suite sees exactly the eight
        // content tools.
        if (app(SeoBridgeContract::class)->available()) {
            $tools = array_merge($tools, [
                GetSeo::class,
                AnalyzeSeoText::class,
                UpdateSeo::class,
            ]);
        }

        return $tools;
    }
}
