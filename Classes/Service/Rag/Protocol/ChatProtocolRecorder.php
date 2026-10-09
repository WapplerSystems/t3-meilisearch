<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag\Protocol;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Service\Rag\Conversation;
use WapplerSystems\Meilisearch\Service\Rag\Escalation\EscalationResolver;

/**
 * Writes every finished chat turn into the chat protocol — opt-in per site
 * via meilisearch.rag.protocol.enabled.
 *
 * Called explicitly by the two places that finish a turn (RagController and
 * RagStreamMiddleware) rather than from AfterRagAnswerEvent: only those know
 * the conversation id and whether the escalation card was actually shown,
 * and the streamed path additionally knows when the visitor dropped the
 * connection mid-answer, which the event never hears about.
 *
 * The protocol is independent of meilisearch.rag.conversation.enabled. With
 * conversation memory off every question simply becomes its own
 * one-turn conversation.
 */
final class ChatProtocolRecorder implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** Status for a streamed answer the visitor cancelled before it finished. */
    public const STATUS_ABORTED = 'aborted';
    /**
     * Answered, but the question was outside the assistant's subject. Kept
     * apart from `ok` so a recipe or a jailbreak attempt neither counts as an
     * answered product question nor shows up among the knowledge gaps.
     */
    public const STATUS_OFF_TOPIC = EscalationResolver::STATUS_OFF_TOPIC;

    /** A visitor in the chat on the site. */
    public const CHANNEL_WEB = 'web';
    /** An AI client through the MCP endpoint. */
    public const CHANNEL_MCP = 'mcp';

    public function __construct(
        private readonly ChatProtocolRepository $repository,
    ) {}

    public function isEnabled(?Site $site): bool
    {
        return $site instanceof Site
            && (bool)$site->getSettings()->get('meilisearch.rag.protocol.enabled', false);
    }

    /**
     * @param list<string> $citedIds
     * @param list<array<string,mixed>> $hits the context hits the model saw
     */
    public function record(
        Site $site,
        ?int $languageId,
        string $conversationId,
        string $question,
        string $answer,
        string $status,
        array $citedIds,
        array $hits,
        bool $escalated,
        string $channel = self::CHANNEL_WEB,
    ): void {
        if (!$this->isEnabled($site) || preg_match(Conversation::ID_PATTERN, $conversationId) !== 1) {
            return;
        }
        if (trim($question) === '' || $status === 'disabled') {
            return;
        }

        $sources = [];
        foreach ($hits as $hit) {
            $id = (string)($hit['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $sources[] = [
                'id' => $id,
                'type' => (string)($hit['type'] ?? ''),
                'title' => (string)($hit['title'] ?? ''),
                'uri' => (string)($hit['uri'] ?? ''),
            ];
        }

        try {
            $this->repository->add(new ProtocolEntry(
                siteIdentifier: $site->getIdentifier(),
                languageId: $languageId ?? 0,
                conversationId: $conversationId,
                question: $question,
                answer: $answer,
                status: $status,
                citedIds: array_values(array_map('strval', $citedIds)),
                sources: $sources,
                escalated: $escalated,
                channel: $channel,
            ));
        } catch (\Throwable $e) {
            // A protocol that cannot be written must never cost the visitor
            // the answer they already received.
            $this->logger?->warning('Chat protocol insert skipped: {msg}', ['msg' => $e->getMessage()]);
        }
    }
}
