<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Domain\Schema;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Service\BoostCalculator;
use WapplerSystems\Meilisearch\Service\LanguageDetector;

/**
 * SchemaProvider for "What's new" product release notes living in
 * tx_linearproducts_domain_model_whatsnew (EXT:linear_products).
 *
 * Unlike news / knowledge resources, this table is multilingual *inline*: a
 * single row carries name_<x> / description_<x> / media_<x> columns for
 * x ∈ {de,en,tr,ru,nl,pl}. One row therefore yields up to six documents —
 * one per site language that (a) maps to one of those suffixes and (b) has a
 * non-empty name. Site languages without a source column (fr, it) are skipped
 * rather than falling back to English, so no wrong-language docs are produced.
 *
 * There is no detail view; WhatsnewController::listAction renders a single
 * highlighted card when called with ?…[whatsnew]=<uid>, so `uri` deep-links to
 * the list page with that argument.
 */
final class WhatsnewSchemaProvider implements SchemaProviderInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const TABLE = 'tx_linearproducts_domain_model_whatsnew';

    /** Site language id → inline column suffix. fr(5) / it(6) have no source columns. */
    private const LANGUAGE_SUFFIX = [
        0 => 'de',
        1 => 'en',
        2 => 'tr',
        3 => 'ru',
        4 => 'nl',
        7 => 'pl',
    ];

    /** @var array<string,int> */
    private array $listPidCache = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ResourceFactory $resourceFactory,
        private readonly BoostCalculator $boostCalculator,
        private readonly LanguageDetector $languageDetector,
    ) {}

    public function getTable(): string
    {
        return self::TABLE;
    }

    public function supports(string $table): bool
    {
        return $table === self::TABLE;
    }

    public function buildDocumentId(int $uid): string
    {
        return $this->buildDocumentIdForLanguage($uid, 0);
    }

    public function buildDocumentIds(int $uid, Site $site): iterable
    {
        // Emit an id for every language we *might* have written, so removal
        // cleans up all variants even if a translation was later emptied.
        foreach (self::LANGUAGE_SUFFIX as $languageId => $_) {
            yield $this->buildDocumentIdForLanguage($uid, $languageId);
        }
    }

    public function fetchDocuments(int $uid, Site $site): iterable
    {
        $row = $this->fetchRow($uid);
        if ($row === null) {
            return;
        }
        yield from $this->documentsForRow($row, $site);
    }

    public function iterateDocuments(Site $site): iterable
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $result = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0)
            )
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            yield from $this->documentsForRow($row, $site);
        }
    }

    public function getAdditionalFields(): array
    {
        // Every field written (type/title/content/uri/language/boost/datetime/
        // imageUrl/accessGroups/contentLanguage) already exists in the base
        // schema or is contributed by another provider (imageUrl, from
        // KnowledgeResourceSchemaProvider). Nothing new to declare → no
        // index-settings change and no full reindex required.
        return [];
    }

    /**
     * @param array<string,mixed> $row
     * @return iterable<array<string,mixed>>
     */
    private function documentsForRow(array $row, Site $site): iterable
    {
        foreach ($site->getLanguages() as $language) {
            $languageId = $language->getLanguageId();
            $suffix = self::LANGUAGE_SUFFIX[$languageId] ?? null;
            if ($suffix === null) {
                continue; // fr / it: no source columns on this table
            }
            $title = trim((string)($row['name_' . $suffix] ?? ''));
            if ($title === '') {
                continue; // not translated for this language
            }
            yield $this->toDocument($row, $languageId, $suffix, $title, $site);
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function toDocument(array $row, int $languageId, string $suffix, string $title, Site $site): array
    {
        $uid = (int)$row['uid'];
        $description = trim(strip_tags((string)($row['description_' . $suffix] ?? '')));
        $content = trim($title . "\n\n" . $description);
        $imageUrl = (int)($row['media_' . $suffix] ?? 0) > 0
            ? $this->resolveImageUrl($uid, 'media_' . $suffix)
            : '';

        return [
            'id' => $this->buildDocumentIdForLanguage($uid, $languageId),
            'type' => 'whatsnew',
            'uid' => $uid,
            'pid' => (int)$row['pid'],
            'language' => $languageId,
            'title' => $title,
            'content' => $content,
            'uri' => $this->buildUri($site, $uid, $languageId),
            'imageUrl' => $imageUrl,
            // No per-record fe_group column on the table → public. Emit empty so
            // the search-time access filter (accessGroups IS EMPTY) treats it
            // uniformly with knowledge resources.
            'accessGroups' => [],
            // `date` is a unix timestamp; reuse the base sortable `datetime`
            // field so What's-new can be ordered newest-first like news.
            'datetime' => (int)($row['date'] ?? 0),
            'boost' => $this->boostCalculator->compositeFor($site, 'whatsnew', null),
            'contentLanguage' => $this->languageDetector->detect($site, $content),
        ];
    }

    private function buildDocumentIdForLanguage(int $uid, int $languageId): string
    {
        return $languageId === 0
            ? 'whatsnew-' . $uid
            : 'whatsnew-' . $uid . '-l' . $languageId;
    }

    private function fetchRow(int $uid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0)
            )
            ->executeQuery()
            ->fetchAssociative();
        return $row === false ? null : $row;
    }

    /**
     * Deep-link to the What's-new list page with ?…[whatsnew]=<uid>, which
     * WhatsnewController::listAction renders as a single highlighted card.
     * Returns '' (and logs) when the list page / router cannot be resolved.
     */
    private function buildUri(Site $site, int $uid, int $languageId): string
    {
        $listPid = $this->resolveListPid($site);
        if ($listPid === 0) {
            return '';
        }
        try {
            $language = $site->getLanguageById($languageId);
            $uri = $site->getRouter()->generateUri($listPid, [
                '_language' => $language,
                'tx_linearproducts_whatsnewlist' => [
                    'controller' => 'Whatsnew',
                    'action' => 'list',
                    'whatsnew' => $uid,
                ],
            ]);
            return (string)$uri;
        } catch (\Throwable $e) {
            $this->logger?->warning(
                'What\'s-new URL generation failed for uid {uid} (lang {lang}): {msg}',
                ['uid' => $uid, 'lang' => $languageId, 'msg' => $e->getMessage()],
            );
            return '';
        }
    }

    /**
     * Resolve the page hosting the What's-new list plugin from the
     * "Whatsnew::list" route enhancer's limitToPages — mirrors
     * NewsPluginRecordSource::resolveDetailPid. Returns 0 when not found.
     */
    private function resolveListPid(Site $site): int
    {
        $id = $site->getIdentifier();
        if (isset($this->listPidCache[$id])) {
            return $this->listPidCache[$id];
        }
        $pid = 0;
        foreach (($site->getConfiguration()['routeEnhancers'] ?? []) as $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            $matches = str_contains((string)($cfg['defaultController'] ?? ''), 'Whatsnew::list');
            foreach (($cfg['routes'] ?? []) as $route) {
                if (is_array($route) && str_contains((string)($route['_controller'] ?? ''), 'Whatsnew::list')) {
                    $matches = true;
                }
            }
            if ($matches) {
                $pages = (array)($cfg['limitToPages'] ?? []);
                if ($pages !== []) {
                    $pid = (int)$pages[0];
                    break;
                }
            }
        }
        return $this->listPidCache[$id] = $pid;
    }

    /**
     * Public URL of the first FAL reference on the given media_<suffix> field,
     * or '' if none / the file is gone. Direct DBAL lookup keeps it cheap
     * during full reindex (mirrors KnowledgeResourceSchemaProvider).
     */
    private function resolveImageUrl(int $uid, string $fieldname): string
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $row = $qb->select('uid_local')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter(self::TABLE)),
                $qb->expr()->eq('fieldname', $qb->createNamedParameter($fieldname)),
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', 0)
            )
            ->orderBy('sorting_foreign', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        if ($row === false) {
            return '';
        }
        try {
            $file = $this->resourceFactory->getFileObject((int)$row['uid_local']);
            $url = (string)$file->getPublicUrl();
            if ($url === '') {
                return '';
            }
            if (str_starts_with($url, '/') || str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, '//')) {
                return $url;
            }
            return '/' . $url;
        } catch (\Throwable) {
            return '';
        }
    }
}
