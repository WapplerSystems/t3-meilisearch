<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\RagTest;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * An embedding client that embeds several texts in one provider call, as
 * the OpenAI-compatible endpoints do with an `input` array. Used to fetch
 * all query vectors of a RAG retrieval at once (QueryVectorProvider::warm())
 * instead of one round trip per query.
 */
interface BatchEmbeddingClientInterface extends EmbeddingClientInterface
{
    /**
     * @param list<string> $texts
     * @return list<list<float>> one vector per text, in input order
     */
    public function embedMany(Site $site, array $texts): array;
}
