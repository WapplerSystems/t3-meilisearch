<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Event;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Dispatched once retrieval has settled on the context documents, after the
 * collapse of release copies and before citation labels: a listener may
 * swap, drop or reorder them.
 *
 * Retrieval ranks by wording, and two kinds of mistake only a site knows
 * about survive it. A document can be the internal twin of a public one —
 * an in-app help topic that is also a knowledge-base article — and should
 * reach the model as the page the visitor can open. And a document can be
 * about a neighbouring subject that shares most words with the asked one
 * (a heating-load lesson for a cooling-load question): left in the context,
 * the model carries its statements over.
 *
 * The question is the visitor's own wording (or the rewritten, self-contained
 * query in a follow-up), not the keyword query. $searchOptions are the
 * options retrieval ran with, so a listener can look up a replacement in the
 * same language and access scope. Nothing changes without a listener.
 */
final class RagContextHitsEvent
{
    /**
     * @param list<array<string,mixed>> $hits
     * @param array<string,mixed> $searchOptions
     */
    public function __construct(
        private readonly Site $site,
        private readonly string $question,
        private array $hits,
        private readonly array $searchOptions = [],
    ) {}

    public function getSite(): Site
    {
        return $this->site;
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getHits(): array
    {
        return $this->hits;
    }

    /**
     * @param list<array<string,mixed>> $hits
     */
    public function setHits(array $hits): void
    {
        $this->hits = array_values($hits);
    }

    /**
     * @return array<string,mixed>
     */
    public function getSearchOptions(): array
    {
        return $this->searchOptions;
    }
}
