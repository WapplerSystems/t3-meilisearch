<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Event;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Dispatched once per retrieval, after the context documents are known and
 * before any of them is rendered as a citation.
 *
 * A citation carries the document title by default, which is ambiguous as
 * soon as a site holds that title more than once — parallel versions of a
 * manual, the same topic in two products, one article in several archives.
 * The answer then reads "[Install packages][Install packages]" with the links
 * pointing somewhere different each time. What distinguishes those documents
 * is site-specific, so it does not belong in this extension: listeners set a
 * label per document id and it replaces the title wherever a citation is
 * rendered — in the streamed answer as well as the server-rendered one.
 *
 * Documents are read-only here. A listener picks wording; it does not reshape
 * the retrieval result (use BeforeRagQueryEvent for that).
 */
final class RagCitationLabelsEvent
{
    /**
     * @var array<string,array{label:string,qualifier:string}>
     */
    private array $labels = [];

    /**
     * @param list<array<string,mixed>> $sources documents about to be cited
     */
    public function __construct(
        private readonly Site $site,
        private readonly array $sources,
    ) {}

    public function getSite(): Site
    {
        return $this->site;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getSources(): array
    {
        return $this->sources;
    }

    /**
     * $label is the citation text, $qualifier the part that tells otherwise
     * identical documents apart (a release, a product, an edition). Passing
     * them separately is what lets a renderer collapse "[Install packages]
     * [Install packages]" into "[Install packages (26, 25)]" with one link
     * per qualifier instead of repeating the label — pass the whole thing as
     * $label if you would rather it stayed verbatim.
     *
     * Blank ids and blank labels are ignored, so a listener can compute
     * unconditionally and let the documents it has nothing to say about fall
     * through to the plain title.
     */
    public function setLabel(string $documentId, string $label, string $qualifier = ''): void
    {
        $label = trim($label);
        if ($documentId !== '' && $label !== '') {
            $this->labels[$documentId] = ['label' => $label, 'qualifier' => trim($qualifier)];
        }
    }

    /**
     * @return array<string,array{label:string,qualifier:string}>
     */
    public function getLabels(): array
    {
        return $this->labels;
    }
}
