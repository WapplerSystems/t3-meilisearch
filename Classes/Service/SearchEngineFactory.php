<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use CmsIg\Seal\Adapter\Meilisearch\MeilisearchAdapter;
use CmsIg\Seal\Engine;
use CmsIg\Seal\Schema\Field\AbstractField;
use CmsIg\Seal\Schema\Field\FloatField;
use CmsIg\Seal\Schema\Field\IdentifierField;
use CmsIg\Seal\Schema\Field\IntegerField;
use CmsIg\Seal\Schema\Field\TextField;
use CmsIg\Seal\Schema\Index;
use CmsIg\Seal\Schema\Schema;
use Meilisearch\Client;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Domain\Schema\SchemaProviderInterface;

/**
 * Builds one SEAL Engine per site. All record types share a single unified
 * index, faceted by `type` — so search across pages + news returns mixed hits
 * with consistent ranking, paging, and facet counts.
 *
 * Engines and clients are memoised per site identifier; building them is cheap
 * but the schema assembly iterates all providers, which is wasteful per request.
 */
final class SearchEngineFactory
{
    /** @var array<string,Engine> */
    private array $engines = [];

    /** @var array<string,Client> */
    private array $clients = [];

    /**
     * @param iterable<SchemaProviderInterface> $schemaProviders
     */
    public function __construct(
        private readonly iterable $schemaProviders,
    ) {}

    public function createForSite(Site $site): ?Engine
    {
        $cacheKey = $site->getIdentifier();
        if (isset($this->engines[$cacheKey])) {
            return $this->engines[$cacheKey];
        }

        $client = $this->createClientForSite($site);
        if ($client === null) {
            return null;
        }

        $adapter = new MeilisearchAdapter($client);
        $schema = $this->buildSchema($site);

        return $this->engines[$cacheKey] = new Engine($adapter, $schema);
    }

    /**
     * Raw Meilisearch PHP client for the site. Needed for features the SEAL
     * adapter does not (yet) expose — embedder settings, hybrid query params.
     * Returns null when the site has no meilisearch.url configured.
     */
    public function createClientForSite(Site $site): ?Client
    {
        $cacheKey = $site->getIdentifier();
        if (isset($this->clients[$cacheKey])) {
            return $this->clients[$cacheKey];
        }

        $settings = $site->getSettings();
        $url = (string)$settings->get('meilisearch.url', '');
        $apiKey = (string)$settings->get('meilisearch.apiKey', '');

        if ($url === '') {
            return null;
        }

        return $this->clients[$cacheKey] = new Client($url, $apiKey !== '' ? $apiKey : null);
    }

    /**
     * The single, unified index name for a site. Same name across all providers.
     */
    public function getIndexName(Site $site): string
    {
        $prefix = (string)$site->getSettings()->get('meilisearch.indexPrefix', '');
        return $prefix . 'search';
    }

    /**
     * Draft index name used by the zero-downtime reindex flow: the
     * IndexerService writes the new corpus here, then calls
     * Meilisearch's swap-indexes API to atomically promote it. Suffix
     * "_draft" so it's instantly recognisable to operators inspecting
     * the engine via the Meilisearch dashboard.
     */
    public function getDraftIndexName(Site $site): string
    {
        return $this->getIndexName($site) . '_draft';
    }

    /**
     * SEAL schema for a site. ApplyMeilisearchSettingsCommand uses this to
     * push the field-flag-derived attributes (searchable/filterable/sortable)
     * alongside the integrator-configured index settings — SEAL itself only
     * pushes them during initial createIndex(), so adding a new sortable
     * field to baseFields() needs an explicit re-push to reach existing
     * indexes.
     */
    public function getSchemaForSite(Site $site): Schema
    {
        return $this->buildSchema($site);
    }

    private function buildSchema(Site $site): Schema
    {
        $indexName = $this->getIndexName($site);

        $fields = $this->baseFields();
        foreach ($this->schemaProviders as $provider) {
            foreach ($provider->getAdditionalFields() as $field) {
                if (!isset($fields[$field->name])) {
                    $fields[$field->name] = $field;
                }
            }
        }

        $indexes = [$indexName => new Index($indexName, $fields)];

        // Zero-downtime reindex builds into a "<name>_draft" index and then
        // atomically swaps it in. The SEAL engine can only operate on indexes
        // declared in its schema (existIndex/createIndex/saveDocument resolve
        // the Index by name), so the draft must be registered too — otherwise
        // ensureSchema() crashes with "existIndex(): … null given". Only added
        // when the mode is enabled to keep the default schema minimal.
        if ((bool)$site->getSettings()->get('meilisearch.indexing.zeroDowntime', false)) {
            $draftName = $this->getDraftIndexName($site);
            $indexes[$draftName] = new Index($draftName, $fields);
        }

        return new Schema($indexes);
    }

    /**
     * Base fields every site has — identifier + the common Page/News overlap.
     *
     * @return array<string,AbstractField>
     */
    private function baseFields(): array
    {
        return [
            'id'          => new IdentifierField('id'),
            'type'        => new TextField('type', searchable: false, filterable: true, facet: true),
            'uid'         => new IntegerField('uid', filterable: true),
            'pid'         => new IntegerField('pid', filterable: true),
            'language'    => new IntegerField('language', filterable: true, facet: true),
            // ISO 639-1 language code of the document CONTENT, detected
            // at index time by LanguageDetector. Distinct from `language`
            // above (which carries the TYPO3 language-overlay id of the
            // record the document was indexed under). A German PDF
            // indexed in the EN-overlay still gets contentLanguage="de"
            // here, so a filter can hide it from EN visitors. Empty
            // string when detection is uncertain — the SearchController
            // treats "" as "unknown, let it through".
            'contentLanguage' => new TextField('contentLanguage', searchable: false, filterable: true, facet: true),
            'title'       => new TextField('title', searchable: true),
            'subtitle'    => new TextField('subtitle', searchable: true),
            'description' => new TextField('description', searchable: true),
            'abstract'    => new TextField('abstract', searchable: true),
            'keywords'    => new TextField('keywords', searchable: true),
            // Canonical full-text field. Pages get their content from the
            // EXT:index bridge (rendered tt_content via the ContentType chain);
            // NewsSchemaProvider and FileSchemaProvider populate it from their
            // own row fields plus Tika output.
            'content'     => new TextField('content', searchable: true),
            // Resolved frontend URL. Not searchable (we don't want substring
            // matches in URLs to drive ranking) but filterable so listeners
            // can scope queries by path / domain.
            'uri'         => new TextField('uri', searchable: false, filterable: true),
            // Editor-controlled relevance multiplier. Composite of the
            // per-type Site-Settings boost (meilisearch.boosts.types.<type>)
            // and the per-record TCA field tx_wsmeilisearch_boost on the
            // source row. Sortable so the apply-settings command can declare
            // it in sortableAttributes — that's the prerequisite for using
            // "boost:desc" as a custom ranking rule. Integrators turn the
            // boost into actual ranking influence by adding "boost:desc" to
            // meilisearch.defaults.rankingRules (between attribute and sort).
            'boost'       => new FloatField('boost', searchable: false, sortable: true),
            // Change fingerprints written by DocumentBatchWriter. They are
            // what lets a reindex tell "unchanged, leave it alone" from
            // "text changed, needs a new vector" — without them every run
            // re-embeds the whole corpus and walks into the embedding
            // provider's tokens-per-minute quota. Not searchable and not
            // filterable: they are read back by id, never queried.
            'docHash'     => new TextField('docHash', searchable: false),
            'embedHash'   => new TextField('embedHash', searchable: false),
        ];
    }
}