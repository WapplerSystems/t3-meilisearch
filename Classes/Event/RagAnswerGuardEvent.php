<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Event;

use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Service\Rag\Conversation;

/**
 * Dispatched once the model's answer is complete and before it is final: a
 * listener may withdraw it and put a fixed reply in its place.
 *
 * The prompt asks the model to answer only from the context, and it mostly
 * does — but not always. Asked whether the hydraulic balancing also works
 * "nach Verfahren B", it answered "Ja, … wählen Sie Verfahren B" with
 * citation markers of the previous turn and nothing in this turn's context
 * that mentions a "Verfahren B". A note under such an answer (see
 * RagAnswerNotesEvent) does not help: the visitor reads the claim first. The
 * check has to replace the answer itself.
 *
 * Runs before the contact card is decided and before the `done` frame, so a
 * replaced answer gets the card of an unanswered question. A streamed answer
 * has been on screen while it was generated; the `done` frame swaps it for
 * the replacement (meilisearch.rag.stream.holdBack keeps it off screen).
 *
 * withdraw() takes the replacement as written, reply tags included
 * ("[reply:no-answer:de]" — expanded afterwards like the model's own) and
 * drops every citation. revise() instead keeps the answer and its citations
 * and only rewrites wording — a product or button name the model left in
 * the wrong language. A revision reaches the visitor as written only with
 * holdBack; a streamed answer gets it with the `done` frame. Nothing
 * changes without a listener.
 */
final class RagAnswerGuardEvent
{
    private ?string $replacement = null;

    private string $reason = '';

    private ?string $revised = null;

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
        private readonly string $topic = '',
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

    /**
     * The answer with fixed replies expanded and citation markers included.
     */
    public function getAnswer(): string
    {
        return $this->revised ?? $this->answer;
    }

    /**
     * Rewrite the wording of the answer, keeping its citations. Later
     * listeners see the revised text in getAnswer(). Ignored once the answer
     * is withdrawn.
     */
    public function revise(string $answer): void
    {
        if ($this->replacement === null) {
            $this->revised = $answer;
        }
    }

    /**
     * The revised answer, null when no listener revised it.
     */
    public function getRevisedAnswer(): ?string
    {
        return $this->revised !== null && $this->revised !== $this->answer ? $this->revised : null;
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

    /**
     * The topic the answer was routed to ("sales", …), '' for none.
     */
    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getLanguageId(): ?int
    {
        return $this->languageId;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation ?? Conversation::empty();
    }

    /**
     * Replace the answer. The first listener to withdraw wins; later ones see
     * isWithdrawn() and should leave it.
     *
     * @param string $reason short id for the log ("ungrounded", "price", …)
     */
    public function withdraw(string $replacement, string $reason): void
    {
        if ($this->replacement !== null) {
            return;
        }
        $this->replacement = $replacement;
        $this->reason = $reason;
    }

    public function isWithdrawn(): bool
    {
        return $this->replacement !== null;
    }

    public function getReplacement(): ?string
    {
        return $this->replacement;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
