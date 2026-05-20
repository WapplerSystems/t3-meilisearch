# 09 — Index a third-party extension's records

The extension ships providers for `pages`, `tx_news_domain_model_news`
and `sys_file`. Anything else — products, events, jobs — gets indexed
into the same unified per-site index by implementing
`SchemaProviderInterface`.

## Pattern

A SchemaProvider is autotagged via `_instanceof` in
`Configuration/Services.yaml`. No registration code needed; just drop
the class in your extension's `Classes/Domain/Schema/`.

## Example: indexing `tx_products_product`

```php
<?php
declare(strict_types=1);

namespace MyVendor\MyShop\Search;

use CmsIg\Seal\Schema\Field\AbstractField;
use CmsIg\Seal\Schema\Field\IntegerField;
use CmsIg\Seal\Schema\Field\TextField;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Domain\Schema\SchemaProviderInterface;

final class ProductSchemaProvider implements SchemaProviderInterface
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getTable(): string
    {
        return 'tx_products_product';
    }

    public function supports(string $table): bool
    {
        return $table === 'tx_products_product';
    }

    public function buildDocumentId(int $uid): string
    {
        return 'product-' . $uid;
    }

    public function buildDocumentIds(int $uid, Site $site): iterable
    {
        // Products have one row per language; one doc id per uid is enough.
        yield $this->buildDocumentId($uid);
    }

    public function fetchDocuments(int $uid, Site $site): iterable
    {
        $row = $this->connectionPool
            ->getQueryBuilderForTable('tx_products_product')
            ->select('uid', 'pid', 'sku', 'name', 'description', 'price', 'sys_language_uid')
            ->from('tx_products_product')
            ->where(
                $this->connectionPool->getQueryBuilderForTable('tx_products_product')
                    ->expr()->eq('uid', $uid),
                'deleted = 0',
                'hidden = 0',
            )
            ->executeQuery()
            ->fetchAssociative();
        if ($row !== false) {
            yield $this->toDocument($row);
        }
    }

    public function iterateDocuments(Site $site): iterable
    {
        $result = $this->connectionPool
            ->getQueryBuilderForTable('tx_products_product')
            ->select('uid', 'pid', 'sku', 'name', 'description', 'price', 'sys_language_uid')
            ->from('tx_products_product')
            ->where('deleted = 0', 'hidden = 0')
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            yield $this->toDocument($row);
        }
    }

    public function getAdditionalFields(): array
    {
        return [
            new TextField('sku', searchable: true, filterable: true),
            new IntegerField('price', filterable: true, sortable: true),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function toDocument(array $row): array
    {
        return [
            'id' => $this->buildDocumentId((int)$row['uid']),
            'type' => 'product',
            'uid' => (int)$row['uid'],
            'pid' => (int)$row['pid'],
            'language' => (int)$row['sys_language_uid'],
            'title' => (string)$row['name'],
            'description' => (string)$row['description'],
            'sku' => (string)$row['sku'],
            'price' => (int)$row['price'],
        ];
    }
}
```

## Wire the DataHandler hook (optional)

If you want product edits to reindex in real time (not just on the
next full reindex), extend `RecordChangeListener`'s table list — the
cleanest path is a small subclass + decoration in `Services.yaml`,
but for one-off cases this works:

```php
// ext_localconf.php
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
    = MyVendor\MyShop\Search\ProductDataHandlerHook::class;
```

(Following the same shape as `RecordChangeListener::processDatamap_afterDatabaseOperations()`.)

## Indexed-tables list (informational)

The `meilisearch.indexedTables` site setting is a hint for tooling;
the actual reindex enumerates every registered provider. So you don't
need to add `tx_products_product` to that setting — it's enough to
ship the provider and the reindex command picks it up.

## Frontend

The default search template already groups facets by `type`. After a
reindex, products show up alongside pages / news, faceted by
`type: product`. The template's hit partial gets the full document
array as `{hit.*}`, so you can render product-specific markup with a
`<f:if condition="{hit.type} == 'product'">` branch.
