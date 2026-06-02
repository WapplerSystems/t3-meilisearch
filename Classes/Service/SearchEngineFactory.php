<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use CmsIg\Seal\Adapter\Meilisearch\MeilisearchAdapter;
use CmsIg\Seal\Engine;
use CmsIg\Seal\Schema\Field\AbstractField;
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

        return new Schema([
            $indexName => new Index($indexName, $fields),
        ]);
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
        ];
    }
}