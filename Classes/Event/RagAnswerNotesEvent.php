<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Event;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Dispatched once an answer is complete, to collect notes shown under it.
 *
 * A note is a fixed sentence the site owner needs next to certain answers —
 * "this value is a preset, set it per project", "values from a standard are
 * for orientation only". Asking the model to add such a sentence works in
 * long answers and is dropped in short ones: measured with Mistral Medium, a
 * one-sentence answer stating a single value left it out every time, under
 * three prompt wordings. A listener decides deterministically from the
 * answer and the documents it cites, and the note is rendered below the
 * source list, never mixed into the model's text.
 *
 * Notes are plain text, deduplicated, in the order they were added. Nothing
 * is added without a listener.
 */
final class RagAnswerNotesEvent
{
    /** @var list<string> */
    private array $notes = [];

    /**
     * @param list<array<string,mixed>> $sources the documents the model saw
     * @param list<string> $citedIds ids of the sources the answer cites
     */
    public function __construct(
        private readonly Site $site,
        private readonly string $question,
        private readonly string $answer,
        private readonly array $sources,
        private readonly array $citedIds,
        private readonly ?int $languageId = null,
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
     * The answer as the model wrote it, citation markers included.
     */
    public function getAnswer(): string
    {
        return $this->answer;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getSources(): array
    {
        return $this->sources;
    }

    /**
     * The sources the answer cites, in retrieval order.
     *
     * @return list<array<string,mixed>>
     */
    public function getCitedSources(): array
    {
        $cited = array_flip($this->citedIds);

        return array_values(array_filter(
            $this->sources,
            static fn (array $source): bool => isset($cited[(string)($source['id'] ?? '')]),
        ));
    }

    /**
     * @return list<string>
     */
    public function getCitedIds(): array
    {
        return $this->citedIds;
    }

    public function getLanguageId(): ?int
    {
        return $this->languageId;
    }

    public function addNote(string $note): void
    {
        $note = trim($note);
        if ($note !== '' && !in_array($note, $this->notes, true)) {
            $this->notes[] = $note;
        }
    }

    /**
     * @return list<string>
     */
    public function getNotes(): array
    {
        return $this->notes;
    }
}
