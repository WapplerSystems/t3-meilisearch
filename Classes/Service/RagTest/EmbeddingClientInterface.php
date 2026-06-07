<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\RagTest;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Minimal embedding-on-demand contract used by RagTestRunner to score
 * "expected vs actual answer" similarity. Mirrors the provider taxonomy
 * of EmbedderConfigurator (ollama / openAi / huggingFace / rest /
 * infomaniak) but lives in its own namespace because the use-case is
 * different: RagTestRunner needs a vector RIGHT NOW for one piece of
 * text; the configurator's job is to tell Meilisearch how to do
 * embedding during indexing.
 *
 * First implementation is OllamaEmbeddingClient. Other providers join
 * the factory iteratively when a real use case appears.
 */
interface EmbeddingClientInterface
{
    /**
     * Match this client against the site's meilisearch.embedder.source
     * setting. The factory uses this to dispatch.
     */
    public function supports(string $sourceName): bool;

    /**
     * Embed one piece of text and return its raw vector. Throws on
     * transport / quota / model errors so the caller can mark the
     * regression test as "error" instead of "fail" — the distinction
     * matters: a 429 from Infomaniak isn't a quality regression.
     *
     * @return list<float>
     */
    public function embed(Site $site, string $text): array;
}
