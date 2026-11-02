<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Event;

use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Service\Rag\Conversation;

/**
 * Dispatched after retrieval, next to the fixed facts and the fact sheet:
 * a listener may add context entries of its own that no search finds.
 *
 * Retrieval matches the wording of the question against documents. Some
 * answers are a lookup instead: "Meine Postleitzahl lautet 52" shares no word
 * with the record of the consultant responsible for 52xxx, and a consultant
 * list in the index would be found for "Berater" but never for "52". A
 * listener can do the lookup itself and hand the model the result.
 *
 * An entry is a context document: at least `id`, `title` and `content`. Give
 * it `type => 'knowledge_resource'` to keep it out of the visible source
 * list; it can be cited all the same. Nothing is added without a listener.
 */
final class RagContextEntriesEvent
{
    /** @var list<array<string,mixed>> */
    private array $entries = [];

    public function __construct(
        private readonly Site $site,
        private readonly string $question,
        private readonly ?int $languageId = null,
        private readonly ?Conversation $conversation = null,
    ) {}

    public function getSite(): Site
    {
        return $this->site;
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    public function getLanguageId(): ?int
    {
        return $this->languageId;
    }

    /**
     * The turns before this one, empty for the first question.
     */
    public function getConversation(): Conversation
    {
        return $this->conversation ?? Conversation::empty();
    }

    /**
     * @param array<string,mixed> $entry
     */
    public function addEntry(array $entry): void
    {
        if (trim((string)($entry['id'] ?? '')) === '' || trim((string)($entry['content'] ?? '')) === '') {
            return;
        }
        $this->entries[] = $entry;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }
}
