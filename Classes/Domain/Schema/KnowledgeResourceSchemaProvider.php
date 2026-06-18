<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Domain\Schema;

use CmsIg\Seal\Schema\Field\IntegerField;
use CmsIg\Seal\Schema\Field\TextField;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Service\BoostCalculator;

/**
 * SchemaProvider for help topics living in tx_wsmeilisearch_knowledge_resource
 * (populated by the ws_meilisearch:import-knowledge-resources CLI or the BE
 * upload form).
 *
 * Each row produces one document with id `help-<uid>`. The `uri` field
 * points at the static-topic delivery middleware path under /hilfe/...
 * (see KnowledgeResourceTopicMiddleware) so RAG sources are clickable.
 *
 * The primary media (FAL-attached image / video) is resolved at fetch
 * time and exposed as `imageUrl` on the document so the FE result-card
 * partial can render a thumbnail.
 */
final class KnowledgeResourceSchemaProvider implements SchemaProviderInterface
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ResourceFactory $resourceFactory,
        private readonly BoostCalculator $boostCalculator,
    ) {}

    public function getTable(): string
    {
        return 'tx_wsmeilisearch_knowledge_resource';
    }

    public function supports(string $table): bool
    {
        return $table === 'tx_wsmeilisearch_knowledge_resource';
    }

    public function buildDocumentId(int $uid): string
    {
        return 'help-' . $uid;
    }

    public function buildDocumentIds(int $uid, Site $site): iterable
    {
        // Each language has its own knowledge resource row → its own uid → its own
        // doc id, matching how NewsSchemaProvider treats translations.
        yield $this->buildDocumentId($uid);
    }

    public function fetchDocuments(int $uid, Site $site): iterable
    {
        $row = $this->fetchRow($uid);
        if ($row !== null) {
            yield $this->toDocument($row, $site);
        }
    }

    public function iterateDocuments(Site $site): iterable
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_wsmeilisearch_knowledge_resource');
        $result = $qb->select(...$this->columnsToSelect())
            ->from('tx_wsmeilisearch_knowledge_resource')
            ->where(
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0)
            )
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            yield $this->toDocument($row, $site);
        }
    }

    public function getAdditionalFields(): array
    {
        return [
            // Help-type label for facet filtering (concept / task / reference).
            new TextField('resourceType', searchable: false, filterable: true, facet: true),
            // Path to the topic relative to the DITA root — RAG sources can
            // reconstruct the /hilfe/... URL from this, and the SchemaProvider
            // also writes it into the standard `uri` field.
            new TextField('helpSourcePath', searchable: false, filterable: true),
            // FAL public URL of the topic's primary illustration, if any.
            // Not searchable; just rendered in the result card.
            new TextField('imageUrl', searchable: false, filterable: false),
            // Title of the parent topic in the TOC — gives RAG citations a
            // breadcrumb so the LLM can disambiguate same-titled topics.
            new TextField('parentTitle', searchable: true),
            new IntegerField('parentUid', filterable: true),
        ];
    }

    /**
     * @return list<string>
     */
    private function columnsToSelect(): array
    {
        return [
            'uid', 'pid', 'sys_language_uid', 'identifier', 'title', 'abstract',
            'body', 'resource_type', 'parent_identifier', 'source_path', 'media',
            'tx_wsmeilisearch_boost',
        ];
    }

    private function fetchRow(int $uid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_wsmeilisearch_knowledge_resource');
        $row = $qb->select(...$this->columnsToSelect())
            ->from('tx_wsmeilisearch_knowledge_resource')
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
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toDocument(array $row, Site $site): array
    {
        $uid = (int)$row['uid'];
        $identifier = (string)$row['identifier'];
        $title = (string)$row['title'];
        $abstract = (string)($row['abstract'] ?? '');
        $body = (string)($row['body'] ?? '');
        $languageId = (int)$row['sys_language_uid'];
        $recordBoost = isset($row['tx_wsmeilisearch_boost']) ? (int)$row['tx_wsmeilisearch_boost'] : null;

        $sourcePath = (string)$row['source_path'];
        $uri = $this->buildUri($sourcePath);

        $parent = $this->resolveParent((string)$row['parent_identifier'], $languageId);

        $imageUrl = (int)$row['media'] > 0
            ? $this->resolveImageUrl($uid)
            : '';

        return [
            'id' => $this->buildDocumentId($uid),
            'type' => 'knowledge_resource',
            'uid' => $uid,
            'pid' => (int)$row['pid'],
            'language' => $languageId,
            'title' => $title,
            // Subtitle slot used as a breadcrumb so list views show "Foo › Bar".
            'subtitle' => $parent['title'] ?? '',
            'abstract' => $abstract,
            'keywords' => $identifier,
            'content' => trim($title . "\n\n" . $abstract . "\n\n" . $body),
            'uri' => $uri,
            'resourceType' => (string)$row['resource_type'],
            'helpSourcePath' => $sourcePath,
            'imageUrl' => $imageUrl,
            'parentTitle' => $parent['title'] ?? '',
            'parentUid' => $parent['uid'] ?? 0,
            'boost' => $this->boostCalculator->compositeFor($site, 'knowledge_resource', $recordBoost),
        ];
    }

    private function buildUri(string $sourcePath): string
    {
        if ($sourcePath === '') {
            return '';
        }
        // The KnowledgeResourceTopicMiddleware mounts the DITA root under /hilfe/, so
        // sourcePath="de/topics/foo.html" → /hilfe/de/topics/foo.html.
        return '/hilfe/' . ltrim($sourcePath, '/');
    }

    /**
     * Look up the parent topic by identifier (within the same language)
     * to give the search hit a "Section › Topic" breadcrumb.
     *
     * @return array{uid?: int, title?: string}
     */
    private function resolveParent(string $parentIdentifier, int $languageId): array
    {
        if ($parentIdentifier === '') {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_wsmeilisearch_knowledge_resource');
        $row = $qb->select('uid', 'title')
            ->from('tx_wsmeilisearch_knowledge_resource')
            ->where(
                $qb->expr()->eq('identifier', $qb->createNamedParameter($parentIdentifier)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0)
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        if ($row === false) {
            return [];
        }
        return ['uid' => (int)$row['uid'], 'title' => (string)$row['title']];
    }

    /**
     * Returns the public URL of the first FAL media reference attached
     * to this knowledge resource row (or empty string if none / if the file is
     * gone). Direct DBAL lookup keeps it cheap during full reindex —
     * the iterator emits 3711 rows and a fully objectified
     * ResourceFactory::getFileReferencesByForeignReference call per row
     * would be wasteful.
     */
    private function resolveImageUrl(int $knowledgeResourceUid): string
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $row = $qb->select('uid_local')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tx_wsmeilisearch_knowledge_resource')),
                $qb->expr()->eq('fieldname', $qb->createNamedParameter('media')),
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($knowledgeResourceUid, \Doctrine\DBAL\ParameterType::INTEGER)),
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
            // FAL returns paths like "fileadmin/helpdocs/X/foo.png"
            // without a leading slash. The FE result-card embeds the URL
            // as `<img src="…">` so a missing slash makes it resolve
            // relative to the current document path (/de/suche/fileadmin/…
            // → 404). Absolute URLs and protocol-relative ones pass through.
            if (str_starts_with($url, '/') || str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, '//')) {
                return $url;
            }
            return '/' . $url;
        } catch (\Throwable) {
            return '';
        }
    }
}