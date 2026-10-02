<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Mcp\Tool;

use WapplerSystems\Meilisearch\Mcp\McpClient;
use WapplerSystems\Meilisearch\Mcp\McpContext;
use WapplerSystems\Meilisearch\Mcp\McpToolException;
use WapplerSystems\Meilisearch\Mcp\McpToolInterface;
use WapplerSystems\Meilisearch\Service\AccessControlFilter;
use WapplerSystems\Meilisearch\Service\SearchService;

final class SearchIndexTool implements McpToolInterface
{
    public function __construct(
        private readonly SearchService $searchService,
        private readonly AccessControlFilter $accessControlFilter,
    ) {}

    public function getName(): string
    {
        return 'search_index';
    }

    public function getDescription(): string
    {
        return 'Full-text and hybrid search of the site index without generating an answer. Use it to find pages, files and other documents and their URLs.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
                'language' => ['type' => 'string', 'description' => 'ISO code like "de" or a site language id such as "1".'],
                'type' => ['type' => 'string', 'description' => 'Optional index type, for example "page", "file" or "news".'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10],
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function getScope(): string
    {
        return McpClient::SCOPE_SEARCH;
    }

    public function isWriting(): bool
    {
        return false;
    }

    public function call(array $arguments, McpContext $context): array
    {
        $query = $arguments['query'] ?? null;
        if (!is_string($query)) {
            throw new McpToolException('query is required and must be a string.');
        }
        $query = trim($query);
        if ($query === '') {
            throw new McpToolException('query must not be empty.');
        }

        $languageValue = $arguments['language'] ?? null;
        if ($languageValue !== null && !is_string($languageValue) && !is_int($languageValue)) {
            throw new McpToolException('language must be an ISO code string or a site language id integer.');
        }
        $language = $context->language($languageValue);

        $type = $arguments['type'] ?? null;
        if ($type !== null && !is_string($type)) {
            throw new McpToolException('type must be a string.');
        }
        if (is_string($type)) {
            $type = trim($type);
            if ($type === '') {
                $type = null;
            }
        }

        $limit = $this->intArgument($arguments, 'limit', 10, 1, 50);
        $page = $this->intArgument($arguments, 'page', 1, 1);

        $filters = ['language' => [$language->getLanguageId()]];
        if ($type !== null) {
            $filters['type'] = [$type];
        }

        $filters = $this->accessControlFilter->applyTo($filters, $context->site, $context->request);

        $result = $this->searchService->search($context->site, $query, [
            'filters' => $filters,
            'page' => $page,
            'perPage' => $limit,
        ]);

        return [
            'totalHits' => $result->totalHits,
            'page' => $result->page,
            'hits' => array_map(fn(array $hit): array => $this->formatHit($hit), $result->hits),
        ];
    }

    /**
     * @param array<string,mixed> $arguments
     */
    private function intArgument(array $arguments, string $name, int $default, int $min, ?int $max = null): int
    {
        if (!array_key_exists($name, $arguments)) {
            return $default;
        }
        $value = $arguments[$name];
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $int = (int)$value;
        } else {
            throw new McpToolException($name . ' must be an integer.');
        }
        if ($int < $min || ($max !== null && $int > $max)) {
            $message = $max !== null
                ? sprintf('%s must be between %d and %d.', $name, $min, $max)
                : sprintf('%s must be at least %d.', $name, $min);
            throw new McpToolException($message);
        }
        return $int;
    }

    /**
     * @param array<string,mixed> $hit
     * @return array{id:string,type:string,title:string,uri:string,excerpt:string}
     */
    private function formatHit(array $hit): array
    {
        return [
            'id' => (string)($hit['id'] ?? ''),
            'type' => (string)($hit['type'] ?? ''),
            'title' => (string)($hit['title'] ?? ''),
            'uri' => (string)($hit['uri'] ?? ''),
            'excerpt' => $this->excerpt($hit),
        ];
    }

    /**
     * First non-empty search field, whitespace collapsed and trimmed to 300 chars.
     *
     * @param array<string,mixed> $hit
     */
    private function excerpt(array $hit): string
    {
        foreach (['abstract', 'description', 'content'] as $field) {
            $value = trim((string)($hit[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
            return mb_substr($value, 0, 300);
        }
        return '';
    }
}
