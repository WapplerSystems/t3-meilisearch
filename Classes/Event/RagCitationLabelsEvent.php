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
     * @var array<string,array{label:string,qualifier:string,note:string}>
     */
    private array $labels = [];

    /**
     * @var array<string,array<string,string>>
     */
    private array $media = [];

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
     *
     * $note is a sentence the reader needs next to the source rather than in
     * the answer — "In newer releases the interface may differ", "The courses
     * are free after registration". It is rendered once below the list of
     * sources however many cited documents carry the same note, so a listener
     * can attach it to every document of a kind without the answer repeating
     * it. Unlike anything the model writes, it cannot be dropped or reworded.
     */
    public function setLabel(string $documentId, string $label, string $qualifier = '', string $note = ''): void
    {
        $label = trim($label);
        if ($documentId !== '' && $label !== '') {
            $this->labels[$documentId] = ['label' => $label, 'qualifier' => trim($qualifier), 'note' => trim($note)];
        }
    }

    /**
     * @return array<string,array{label:string,qualifier:string,note:string}>
     */
    public function getLabels(): array
    {
        return $this->labels;
    }

    /**
     * Marks a document as a media source (a video lesson, a recording) so it
     * is shown as a card in its own block under the answer instead of a line
     * in the numbered source list, and its inline reference carries a play
     * mark. Everything the card says comes from here — this extension adds no
     * wording of its own, so the texts stay in the listener's language:
     *
     *   title      what the reader sees in it ("Import the Excel room book")
     *   context    where it lives ("Course „Room book“ · Lesson 2.1")
     *   meta       one quiet line ("Excerpt 1:07–2:50 (1:43 min) · LINEAR Building")
     *   start      the position badge on the preview ("1:07"), optional
     *   image      URL of a still for the preview, optional; without one the
     *              preview stays a coloured area with the play mark
     *   url        the link of the card
     *   cta        the link text ("Watch from 1:07")
     *   intro      a sentence above the card when it is the first one shown
     *   heading    the heading of the block
     *   more       the line above the further cards
     *
     * The first card is the best-ranked cited media document; at most three
     * are shown. Unknown keys are ignored, missing ones render as nothing.
     *
     * @param array<string,string> $media
     */
    public function setMedia(string $documentId, array $media): void
    {
        if ($documentId === '') {
            return;
        }
        $clean = [];
        foreach (['title', 'context', 'meta', 'start', 'image', 'url', 'cta', 'intro', 'heading', 'more'] as $key) {
            $value = trim((string)($media[$key] ?? ''));
            if ($value !== '') {
                $clean[$key] = $value;
            }
        }
        if (isset($clean['title'])) {
            $this->media[$documentId] = $clean;
        }
    }

    /**
     * @return array<string,array<string,string>>
     */
    public function getMedia(): array
    {
        return $this->media;
    }
}
