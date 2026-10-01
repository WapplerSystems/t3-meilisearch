<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag\Protocol;

/**
 * One stored chat turn: what the visitor asked, what the assistant answered,
 * on which documents, and whether the visitor was offered a way to a human.
 *
 * $sources holds every context hit the model saw, not only the cited ones —
 * for quality control the interesting case is exactly the answer that had
 * the right document in front of it and still did not use it.
 */
final class ProtocolEntry
{
    public const TABLE = 'tx_wsmeilisearch_rag_protocol';

    /**
     * @param list<string> $citedIds
     * @param list<array{id:string,type:string,title:string,uri:string}> $sources
     */
    public function __construct(
        public readonly string $siteIdentifier,
        public readonly int $languageId,
        public readonly string $conversationId,
        public readonly string $question,
        public readonly string $answer,
        public readonly string $status,
        public readonly array $citedIds = [],
        public readonly array $sources = [],
        public readonly bool $escalated = false,
        public readonly int $crdate = 0,
        public readonly int $uid = 0,
    ) {}

    /**
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            siteIdentifier: (string)($row['site_identifier'] ?? ''),
            languageId: (int)($row['language_id'] ?? 0),
            conversationId: (string)($row['conversation_id'] ?? ''),
            question: (string)($row['question'] ?? ''),
            answer: (string)($row['answer'] ?? ''),
            status: (string)($row['status'] ?? ''),
            citedIds: array_values(array_map('strval', self::decodeList($row['cited_ids'] ?? null))),
            sources: array_values(array_filter(self::decodeList($row['sources'] ?? null), 'is_array')),
            escalated: (bool)($row['escalated'] ?? false),
            crdate: (int)($row['crdate'] ?? 0),
            uid: (int)($row['uid'] ?? 0),
        );
    }

    /**
     * @return array<string,int|string>
     */
    public function toRow(): array
    {
        return [
            'crdate' => $this->crdate > 0 ? $this->crdate : time(),
            'site_identifier' => mb_substr($this->siteIdentifier, 0, 64),
            'language_id' => $this->languageId,
            'conversation_id' => $this->conversationId,
            'question' => $this->question,
            'answer' => $this->answer,
            'status' => mb_substr($this->status, 0, 16),
            'cited_ids' => (string)json_encode($this->citedIds, JSON_UNESCAPED_UNICODE),
            'sources' => (string)json_encode($this->sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'escalated' => $this->escalated ? 1 : 0,
        ];
    }

    public function wasCited(string $sourceId): bool
    {
        return in_array($sourceId, $this->citedIds, true);
    }

    /**
     * @return list<mixed>
     */
    private static function decodeList(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
