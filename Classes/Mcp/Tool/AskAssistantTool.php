<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Mcp\Tool;

use WapplerSystems\Meilisearch\Mcp\McpClient;
use WapplerSystems\Meilisearch\Mcp\McpContext;
use WapplerSystems\Meilisearch\Mcp\McpToolException;
use WapplerSystems\Meilisearch\Mcp\McpToolInterface;
use WapplerSystems\Meilisearch\Service\AccessControlFilter;
use WapplerSystems\Meilisearch\Service\Rag\Conversation;
use WapplerSystems\Meilisearch\Service\Rag\Protocol\ChatProtocolRecorder;
use WapplerSystems\Meilisearch\Service\Rag\Protocol\ChatProtocolRepository;
use WapplerSystems\Meilisearch\Service\Rag\Protocol\ProtocolEntry;
use WapplerSystems\Meilisearch\Service\Rag\RagService;
use WapplerSystems\Meilisearch\Service\Rag\Turn;

final class AskAssistantTool implements McpToolInterface
{
    public function __construct(
        private readonly RagService $ragService,
        private readonly ChatProtocolRepository $chatProtocolRepository,
        private readonly ChatProtocolRecorder $chatProtocolRecorder,
        private readonly AccessControlFilter $accessControlFilter,
    ) {}

    public function getName(): string
    {
        return 'ask_assistant';
    }

    public function getDescription(): string
    {
        return 'Asks the website AI assistant exactly like a visitor in the chat. Answers come only from the site documentation and hand-written knowledge, with sources. Pass the returned conversationId to ask a follow-up in the same conversation. Status "clarify" means the assistant asks back; answer with a follow-up.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'question' => ['type' => 'string', 'maxLength' => 2000],
                'language' => ['type' => 'string', 'description' => 'ISO code like "de" or a site language id such as "1".'],
                'conversationId' => ['type' => 'string', 'pattern' => '^[0-9a-f]{32}$'],
            ],
            'required' => ['question'],
            'additionalProperties' => false,
        ];
    }

    public function getScope(): string
    {
        return McpClient::SCOPE_ASK;
    }

    public function isWriting(): bool
    {
        return false;
    }

    public function call(array $arguments, McpContext $context): array
    {
        $site = $context->site;

        $question = $arguments['question'] ?? null;
        if (!is_string($question)) {
            throw new McpToolException('question is required and must be a string.');
        }
        $question = trim($question);
        if ($question === '') {
            throw new McpToolException('question must not be empty.');
        }
        if (mb_strlen($question) > 2000) {
            throw new McpToolException('question must be at most 2000 characters.');
        }

        $languageValue = $arguments['language'] ?? null;
        if ($languageValue !== null && !is_string($languageValue) && !is_int($languageValue)) {
            throw new McpToolException('language must be an ISO code string or a site language id integer.');
        }
        $language = $context->language($languageValue);
        $languageId = $language->getLanguageId();

        $conversationId = $arguments['conversationId'] ?? null;
        if ($conversationId !== null) {
            if (!is_string($conversationId) || preg_match(Conversation::ID_PATTERN, $conversationId) !== 1) {
                throw new McpToolException('conversationId must be 32 lowercase hex characters.');
            }

            $rows = $this->chatProtocolRepository->findByConversation($conversationId, $site->getIdentifier());
            $turns = [];
            foreach ($rows as $row) {
                if ($row instanceof ProtocolEntry) {
                    $entry = $row;
                } elseif (is_array($row)) {
                    $entry = ProtocolEntry::fromRow($row);
                } else {
                    continue;
                }

                if (!in_array($entry->status, ['ok', 'clarify', ChatProtocolRecorder::STATUS_OFF_TOPIC], true)) {
                    continue;
                }

                $turns[] = new Turn(
                    question: $entry->question,
                    answer: $entry->answer,
                    citedIds: $entry->citedIds,
                    kind: $entry->status === 'clarify' ? Turn::KIND_CLARIFICATION : Turn::KIND_ANSWER,
                );
            }

            $maxTurns = max(1, (int)$site->getSettings()->get('meilisearch.rag.conversation.maxTurns', 3));
            $turns = array_values(array_slice($turns, -$maxTurns));
            $conversation = new Conversation($turns, $conversationId);
        } else {
            $conversation = Conversation::empty()->withId();
        }

        $options = ['conversation' => $conversation];
        if ((bool)$site->getSettings()->get('meilisearch.rag.restrictToCurrentLanguage', true)) {
            $options['filters'] = ['language' => [$languageId]];
        }
        $options['language'] = $languageId;

        $existingFilters = $options['filters'] ?? [];
        $options['filters'] = $this->accessControlFilter->applyTo($existingFilters, $site, $context->request);

        $answer = $this->ragService->ask($site, $question, $options);

        $this->chatProtocolRecorder->record(
            site: $site,
            languageId: $languageId,
            conversationId: $conversation->id,
            question: $question,
            answer: $answer->answer,
            status: $answer->outOfScope ? ChatProtocolRecorder::STATUS_OFF_TOPIC : $answer->status,
            citedIds: $answer->citedIds,
            hits: $answer->sources,
            escalated: false,
            channel: ChatProtocolRecorder::CHANNEL_MCP,
        );

        $result = [
            'conversationId' => $conversation->id,
            'status' => $answer->outOfScope ? ChatProtocolRecorder::STATUS_OFF_TOPIC : $answer->status,
            'answer' => $answer->answer,
        ];
        // Fixed notes the site shows under this answer (RagAnswerNotesEvent).
        if ($answer->notes !== []) {
            $result['answerNotes'] = $answer->notes;
        }

        if ($answer->status === 'clarify') {
            $clarifyingOptions = [];
            foreach ($answer->suggestions as $suggestion) {
                if (($suggestion['type'] ?? '') !== 'clarify') {
                    continue;
                }
                $clarifyingOptions[] = [
                    'label' => (string)($suggestion['label'] ?? ''),
                    'value' => (string)($suggestion['value'] ?? ''),
                ];
            }
            $result['clarifyingOptions'] = $clarifyingOptions;
        }

        $sources = [];
        foreach ($answer->getPublicSources() as $source) {
            $sourceId = (string)($source['id'] ?? '');
            if ($sourceId === '') {
                continue;
            }
            $sources[] = [
                'id' => $sourceId,
                'type' => (string)($source['type'] ?? ''),
                'title' => (string)($source['title'] ?? ''),
                'uri' => (string)($source['uri'] ?? ''),
                'cited' => in_array($sourceId, $answer->citedIds, true),
            ];
        }
        $result['sources'] = $sources;

        $protocolEnabled = (bool)$site->getSettings()->get('meilisearch.rag.protocol.enabled', false);
        $result['followUpPossible'] = $protocolEnabled;

        $notes = [];
        if (!$protocolEnabled) {
            $notes[] = 'Follow-up conversations are disabled on this site; conversationId is only valid for this call.';
        }
        if ($answer->status === 'failed') {
            $notes[] = 'The assistant could not answer (status failed).';
        }
        if ($notes !== []) {
            $result['note'] = implode(' ', $notes);
        }

        return $result;
    }
}
