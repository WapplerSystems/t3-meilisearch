<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

/**
 * Immutable container for a multi-turn RAG conversation. Each Turn is a
 * (question, answer) pair with the source IDs the LLM cited that round.
 *
 * Conversation state crosses request boundaries — it serializes to a plain
 * array via toArray() / fromArray() so it round-trips cleanly through the
 * TYPO3 frontend user session (or any other storage that takes JSON-safe
 * data).
 *
 * @phpstan-type TurnArray array{question:string,answer:string,citedIds:list<string>,kind?:string}
 */
final class Conversation
{
    /**
     * Format of {@see $id}: 32 lowercase hex digits (128 random bits).
     */
    public const ID_PATTERN = '/^[0-9a-f]{32}$/';

    /**
     * @param list<Turn> $turns ordered oldest → newest
     * @param string $id stable identifier of this conversation, the key of
     *        its rows in the chat protocol. Deliberately NOT the session id:
     *        it is handed to the visitor (e.g. as a parameter of a contact
     *        form link), and a session id in a URL is a session takeover
     *        waiting to happen. Empty until {@see withId()} assigns one.
     */
    public function __construct(
        public readonly array $turns = [],
        public readonly string $id = '',
    ) {}

    /**
     * This conversation with an id, generating one if it has none yet.
     * Called once per request before the turn is answered, so the id exists
     * while the answer — and the escalation card that may link to its
     * protocol — is being produced.
     */
    public function withId(): self
    {
        return $this->id !== '' ? $this : new self($this->turns, bin2hex(random_bytes(16)));
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->turns === [];
    }

    /**
     * Returns a new Conversation with an extra turn appended; older turns
     * beyond $maxTurns are dropped (oldest first) so the prompt stays
     * bounded regardless of how long the user keeps chatting.
     */
    public function withTurn(Turn $turn, int $maxTurns): self
    {
        $turns = $this->turns;
        $turns[] = $turn;
        if ($maxTurns > 0 && count($turns) > $maxTurns) {
            $turns = array_slice($turns, -$maxTurns);
        }
        return new self(array_values($turns), $this->id);
    }

    /**
     * Convert the conversation into chat-completion messages. The caller
     * prepends the system prompt and appends the new user turn (with fresh
     * search context) on top of these.
     *
     * @return list<array{role:string,content:string}>
     */
    public function toMessages(): array
    {
        $messages = [];
        foreach ($this->turns as $turn) {
            $messages[] = ['role' => 'user', 'content' => $turn->question];
            $messages[] = ['role' => 'assistant', 'content' => $turn->answer];
        }
        return $messages;
    }

    /**
     * @return array{id: string, turns: list<TurnArray>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'turns' => array_map(static fn (Turn $t) => [
                'question' => $t->question,
                'answer' => $t->answer,
                'citedIds' => $t->citedIds,
                'kind' => $t->kind,
                // The cited documents, so a reloaded page can render the
                // references instead of an answer that lost them.
                'citations' => $t->citations,
                // The buttons offered under the answer, so a reload keeps them.
                'suggestions' => $t->suggestions,
            ], $this->turns),
        ];
    }

    /**
     * True when the most recent turn was a clarifying question the assistant
     * asked back (rather than an answer). The triage step uses this to avoid
     * asking for clarification twice in a row — the user's reply to a
     * clarifying question is always treated as answerable.
     */
    public function lastTurnIsClarification(): bool
    {
        // array_key_last() is null on an empty history, and null as an array
        // offset is deprecated since PHP 8.5 — TYPO3 turns that into an
        // exception, which killed every first question of a conversation.
        $lastKey = array_key_last($this->turns);
        if ($lastKey === null) {
            return false;
        }
        $last = $this->turns[$lastKey];
        return $last instanceof Turn && $last->isClarification();
    }

    /**
     * @param array{turns?: list<TurnArray>}|array<string,mixed>|null $data
     */
    public static function fromArray(?array $data): self
    {
        if (!is_array($data) || !is_array($data['turns'] ?? null)) {
            return self::empty();
        }
        // Sessions written before ids existed carry none; withId() gives
        // them one on their next turn.
        $id = (string)($data['id'] ?? '');
        $id = preg_match(self::ID_PATTERN, $id) === 1 ? $id : '';
        $turns = [];
        foreach ($data['turns'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $kind = (string)($row['kind'] ?? Turn::KIND_ANSWER);
            $turns[] = new Turn(
                question: (string)($row['question'] ?? ''),
                answer: (string)($row['answer'] ?? ''),
                citedIds: array_values(array_map('strval', (array)($row['citedIds'] ?? []))),
                kind: $kind === Turn::KIND_CLARIFICATION ? Turn::KIND_CLARIFICATION : Turn::KIND_ANSWER,
                // Absent in turns stored before citations were kept — those
                // simply render without references.
                citations: array_values(array_filter(
                    (array)($row['citations'] ?? []),
                    static fn ($c): bool => is_array($c),
                )),
                suggestions: array_values(array_filter(
                    (array)($row['suggestions'] ?? []),
                    static fn ($c): bool => is_array($c),
                )),
            );
        }
        return new self($turns, $id);
    }
}
