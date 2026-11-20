<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Mcp\Tool;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Mcp\McpClient;
use WapplerSystems\Meilisearch\Mcp\McpContext;
use WapplerSystems\Meilisearch\Mcp\McpToolException;
use WapplerSystems\Meilisearch\Mcp\McpToolInterface;
use WapplerSystems\Meilisearch\Service\AccessControlFilter;
use WapplerSystems\Meilisearch\Service\Rag\PromptBuilder;
use WapplerSystems\Meilisearch\Service\SearchService;

/**
 * The full indexed text of one source id, and the block the chat model reads
 * for it (header with the prompt meta fields, body cut at maxContextChars).
 *
 * Reviewing the protocol, LINEAR saw only source ids and the 300-character
 * excerpts of search_index — whether a statement stood in the context or the
 * model added it could not be told (Gregor 19.11.2026). The site's own
 * context entries that are no index documents (fact sheet, fixed facts) are
 * answered from their setting. Documents behind a login stay out, as in the
 * other read tools.
 */
final class DocumentGetTool implements McpToolInterface
{
    /** Context entries the chat builds from a site setting, not from the index. */
    private const SITE_ENTRIES = [
        'site-fact-sheet' => 'meilisearch.rag.factSheet',
        'site-facts' => 'meilisearch.rag.fixedFacts',
    ];

    /** Index internals that say nothing about the content. */
    private const HIDDEN_FIELDS = ['_vectors', '_rankingScore', '_rankingScoreDetails', '_formatted', '_matchesPosition', 'accessGroups', 'boost'];

    public function __construct(
        private readonly SearchService $searchService,
        private readonly AccessControlFilter $accessControlFilter,
        private readonly PromptBuilder $promptBuilder,
    ) {}

    public function getName(): string
    {
        return 'document_get';
    }

    public function getDescription(): string
    {
        return 'Returns one source the chat uses, by its id as cited in answers and listed in the protocol (pages-123, pages-123-l1, elearning-45, reference-6, knowledge-7, whatsnew-8, site-fact-sheet …): title, URL, language, release and product labels, when it was indexed, the full indexed text ("content") and the block the chat model reads for it ("contextText": header with the meta fields, text cut at the context limit). Use it to check whether a statement of an answer was in its sources.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_-]{0,63}$', 'description' => 'Source id, e.g. "pages-10924" or "elearning-4520".'],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function getScope(): string
    {
        return McpClient::SCOPE_INDEX_READ;
    }

    public function isWriting(): bool
    {
        return false;
    }

    public function call(array $arguments, McpContext $context): array
    {
        $id = $arguments['id'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $id) !== 1) {
            throw new McpToolException('id is required, e.g. "pages-10924".');
        }
        $settings = $context->site->getSettings();
        $maxChars = max(100, (int)$settings->get('meilisearch.rag.maxContextChars', 1500));
        $metaFields = array_values(array_filter(array_map(
            static fn ($f): string => trim((string)$f),
            (array)$settings->get('meilisearch.rag.promptMetaFields', []),
        )));

        if (isset(self::SITE_ENTRIES[$id])) {
            $text = $this->siteText($context->site, self::SITE_ENTRIES[$id]);
            if ($text === '') {
                throw new McpToolException('This site has no ' . $id . '.');
            }
            $hit = ['id' => $id, 'type' => 'knowledge_resource', 'title' => $id, 'content' => $text, PromptBuilder::FULL_TEXT => true];

            return [
                'id' => $id,
                'source' => 'site setting ' . self::SITE_ENTRIES[$id],
                'content' => $text,
                'contextText' => $this->promptBuilder->contextBlock($hit, $maxChars, $metaFields),
            ];
        }

        $access = $this->accessControlFilter->applyTo([], $context->site, $context->request)['__rawFilters'] ?? [];
        $documents = $this->searchService->documents($context->site, [$id], array_values(array_map('strval', (array)$access)));
        if ($documents === []) {
            throw new McpToolException('No document with this id in the index of this site (or it is behind a login).');
        }
        $document = $documents[0];
        $hit = $document;
        // Site entries the chat passes in full are no index documents; an
        // index document is cut like in the chat.
        unset($hit[PromptBuilder::FULL_TEXT]);

        $fields = array_diff_key($document, array_flip(self::HIDDEN_FIELDS));
        $content = (string)($fields['content'] ?? '');
        unset($fields['content']);
        if (isset($fields['indexedAt']) && is_numeric($fields['indexedAt'])) {
            $fields['indexedAt'] = date('c', (int)$fields['indexedAt']);
        }

        return [
            'id' => $id,
            'fields' => $fields,
            'content' => $content,
            'contentLength' => mb_strlen($content),
            'contextLimit' => $maxChars,
            'contextText' => $this->promptBuilder->contextBlock($hit, $maxChars, $metaFields),
        ];
    }

    /** Same resolution as the chat: inline or "file:<path>" under the site's configuration directory. */
    private function siteText(Site $site, string $key): string
    {
        $value = (string)$site->getSettings()->get($key, '');
        if (!str_starts_with($value, 'file:')) {
            return $value;
        }
        $siteDirectory = realpath(Environment::getConfigPath() . '/sites/' . $site->getIdentifier());
        $file = realpath($siteDirectory . '/' . trim(substr($value, 5)));

        return $siteDirectory !== false && $file !== false && str_starts_with($file, $siteDirectory . '/') && is_file($file)
            ? (string)file_get_contents($file) : '';
    }
}
