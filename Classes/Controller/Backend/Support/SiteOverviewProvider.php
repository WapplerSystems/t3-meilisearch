<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller\Backend\Support;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Read-only site-settings helpers for backend module controllers.
 * Three jobs:
 *
 *  - {@see firstNonEmpty()} → walk every site, return the first
 *    non-empty trimmed value for a setting key. Used as
 *    informational placeholders in the BE forms ("if you leave this
 *    empty, the importer falls back to …").
 *
 *  - {@see knownLanguages()} → union of every TYPO3 language
 *    declared on any site, formatted as `<uid> — <title>`. Used to
 *    populate language dropdowns in the helpdoc importer forms.
 *
 *  - {@see describeDesiredEmbedder()} / {@see describeRagConfig()}
 *    → pure view-side mappers from a Site's settings dictionary
 *    onto the shape the Diagnostics template expects. Lived in
 *    DiagnoseController as private methods before — moved here so
 *    the controller stays focused on dispatch + flow.
 *
 * Stateless, can be cached freely. The Site objects come from
 * SiteFinder which already memoises per-request, so the loops are
 * cheap.
 */
final class SiteOverviewProvider
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * First non-empty trimmed value of $settingKey across all
     * configured sites. Returns $fallback when no site has it set.
     */
    public function firstNonEmpty(string $settingKey, string $fallback = ''): string
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            $value = trim((string)$site->getSettings()->get($settingKey, ''));
            if ($value !== '') {
                return $value;
            }
        }
        return $fallback;
    }

    /**
     * Union of every TYPO3 language declared on any site. Keyed by
     * `sys_language_uid`, labelled "<uid> — <title>". First site to
     * declare a language wins the title.
     *
     * @return array<int, string>
     */
    public function knownLanguages(): array
    {
        $result = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            foreach ($site->getAllLanguages() as $lang) {
                $id = $lang->getLanguageId();
                $result[$id] = $result[$id] ?? sprintf('%d — %s', $id, $lang->getTitle());
            }
        }
        ksort($result);
        return $result;
    }

    /**
     * Map the embedder slice of site settings to the Diagnostics card
     * shape. apiKey deliberately omitted — never rendered. Returns
     * null when no embedder is configured for the site.
     *
     * @return array<string,mixed>|null
     */
    public function describeDesiredEmbedder(Site $site): ?array
    {
        $settings = $site->getSettings();
        $source = trim((string)$settings->get('meilisearch.embedder.source', ''));
        if ($source === '') {
            return null;
        }
        return array_filter([
            'source' => $source,
            'model' => trim((string)$settings->get('meilisearch.embedder.model', '')),
            'url' => trim((string)$settings->get('meilisearch.embedder.url', '')),
            'dimensions' => (int)$settings->get('meilisearch.embedder.dimensions', 0) ?: null,
            'documentTemplate' => trim((string)$settings->get('meilisearch.embedder.documentTemplate', '')),
            'semanticRatio' => (float)$settings->get('meilisearch.embedder.semanticRatio', 0.5),
        ], static fn ($v) => $v !== '' && $v !== null);
    }

    /**
     * Map the RAG slice of site settings to the Diagnostics card shape.
     * Returns a hasApiKey boolean instead of the key itself so the
     * template can show a "✓ set" badge without ever rendering the
     * secret. Returns null when no RAG provider is configured.
     *
     * @return array<string,mixed>|null
     */
    public function describeRagConfig(Site $site): ?array
    {
        $settings = $site->getSettings();
        $provider = trim((string)$settings->get('meilisearch.rag.provider', ''));
        if ($provider === '') {
            return null;
        }
        return [
            'provider' => $provider,
            'model' => trim((string)$settings->get('meilisearch.rag.model', '')),
            'url' => trim((string)$settings->get('meilisearch.rag.url', '')),
            'hasApiKey' => trim((string)$settings->get('meilisearch.rag.apiKey', '')) !== '',
            'useHybrid' => (bool)$settings->get('meilisearch.rag.useHybrid', true),
            'conversationEnabled' => (bool)$settings->get('meilisearch.rag.conversation.enabled', false),
            'maxContextHits' => (int)$settings->get('meilisearch.rag.maxContextHits', 5),
            'temperature' => (float)$settings->get('meilisearch.rag.temperature', 0.2),
        ];
    }
}
