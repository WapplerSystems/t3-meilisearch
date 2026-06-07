<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\RagTest;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Picks the right embedding client for a given site by matching the
 * site's meilisearch.embedder.source setting against each registered
 * client's supports() vote. Tagged-iterator wired in Services.yaml
 * (ws_meilisearch.embedding_client).
 */
final class EmbeddingClientRegistry
{
    /** @var list<EmbeddingClientInterface> */
    private array $clients;

    /**
     * @param iterable<EmbeddingClientInterface> $clients DI tagged_iterator
     */
    public function __construct(iterable $clients)
    {
        $this->clients = is_array($clients) ? array_values($clients) : iterator_to_array($clients, false);
    }

    /**
     * @throws \RuntimeException when the site has no embedder source set
     *                            or no registered client supports it
     */
    public function forSite(Site $site): EmbeddingClientInterface
    {
        $source = trim((string)$site->getSettings()->get('meilisearch.embedder.source', ''));
        if ($source === '') {
            throw new \RuntimeException('Site has no meilisearch.embedder.source configured');
        }
        foreach ($this->clients as $client) {
            if ($client->supports($source)) {
                return $client;
            }
        }
        throw new \RuntimeException(sprintf(
            'No EmbeddingClient supports embedder source "%s". Registered: %s',
            $source,
            implode(', ', array_map(static fn ($c) => $c::class, $this->clients)),
        ));
    }
}
