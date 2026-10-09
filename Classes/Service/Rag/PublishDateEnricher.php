<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Adds the publication date (`published`, YYYY-MM-DD) to the page hits of an
 * answer context, read from the pages table at answer time.
 *
 * Without a date every entry reads as current. Asked for the next release,
 * the assistant announced a preview of version 23.2 from a 2023 blog post;
 * asked for a discount, it offered a campaign that had ended in November
 * 2023. With the date in the context header (meilisearch.rag.promptMetaFields
 * lists `published`) and the date of today in the prompt ({{today}}), the
 * model can tell an old announcement from a current one.
 *
 * Read at answer time rather than indexed: it costs one query for at most a
 * handful of uids and needs no reindex of the existing corpus.
 *
 * Source: pages.publish_date (EXT:blog) when set, otherwise crdate.
 * meilisearch.rag.publishDate.doktypes limits it to page types where a date
 * means something (e.g. [137] for blog posts) — on a company page "published
 * 2022" would only make the model doubt facts that are still valid. Empty
 * list: every page.
 */
final class PublishDateEnricher
{
    public const FIELD = 'published';

    /** Whether pages has EXT:blog's publish_date; read once per process. */
    private ?bool $hasPublishDate = null;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<array<string,mixed>> $hits
     * @return list<array<string,mixed>>
     */
    public function enrich(Site $site, array $hits): array
    {
        $settings = $site->getSettings();
        $metaFields = $settings->get('meilisearch.rag.promptMetaFields', []);
        // Nobody would see the field — skip the query.
        if (!is_array($metaFields) || !in_array(self::FIELD, $metaFields, true)) {
            return $hits;
        }

        $uids = [];
        foreach ($hits as $hit) {
            if ((string)($hit['type'] ?? '') === 'page' && (int)($hit['uid'] ?? 0) > 0) {
                $uids[] = (int)$hit['uid'];
            }
        }
        if ($uids === []) {
            return $hits;
        }

        $dates = $this->dates(array_values(array_unique($uids)), $this->doktypes($settings));
        foreach ($hits as $i => $hit) {
            $uid = (int)($hit['uid'] ?? 0);
            if ((string)($hit['type'] ?? '') === 'page' && isset($dates[$uid])) {
                $hits[$i][self::FIELD] = $dates[$uid];
            }
        }

        return $hits;
    }

    /**
     * @param list<int> $uids
     * @param list<int> $doktypes
     * @return array<int, string>
     */
    private function dates(array $uids, array $doktypes): array
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $this->hasPublishDate ??= isset($connection->createSchemaManager()->listTableColumns('pages')['publish_date']);

        $qb = $connection->createQueryBuilder();
        $qb->select('uid', 'crdate', 'doktype')
            ->from('pages')
            ->where($qb->expr()->in('uid', $qb->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)));
        if ($this->hasPublishDate) {
            $qb->addSelect('publish_date');
        }

        $dates = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            if ($doktypes !== [] && !in_array((int)$row['doktype'], $doktypes, true)) {
                continue;
            }
            $timestamp = (int)($row['publish_date'] ?? 0) ?: (int)$row['crdate'];
            if ($timestamp > 0) {
                $dates[(int)$row['uid']] = date('Y-m-d', $timestamp);
            }
        }

        return $dates;
    }

    /**
     * @return list<int>
     */
    private function doktypes(object $settings): array
    {
        $value = $settings->get('meilisearch.rag.publishDate.doktypes', []);
        if (!is_array($value)) {
            $value = explode(',', (string)$value);
        }

        return array_values(array_filter(array_map('intval', $value), static fn (int $d): bool => $d > 0));
    }
}
