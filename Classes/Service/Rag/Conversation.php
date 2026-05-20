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
 * @phpstan-type TurnArray array{question:string,answer:string,citedIds:list<string>}
 */
final class Conversation
{
    /**
     * @param list<Turn> $turns ordered oldest → newest
     */
    public function __construct(
        public readonly array $turns = [],
    ) {}

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
        return new self(array_values($turns));
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
     * @return array{turns: list<TurnArray>}
     */
    public function toArray(): array
    {
        return [
            'turns' => array_map(static fn (Turn $t) => [
                'question' => $t->question,
                'answer' => $t->answer,
                'citedIds' => $t->citedIds,
            ], $this->turns),
        ];
    }

    /**
     * @param array{turns?: list<TurnArray>}|array<string,mixed>|null $data
     */
    public static function fromArray(?array $data): self
    {
        if (!is_array($data) || !is_array($data['turns'] ?? null)) {
            return self::empty();
        }
        $turns = [];
        foreach ($data['turns'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $turns[] = new Turn(
                question: (string)($row['question'] ?? ''),
                answer: (string)($row['answer'] ?? ''),
                citedIds: array_values(array_map('strval', (array)($row['citedIds'] ?? []))),
            );
        }
        return new self($turns);
    }
}
