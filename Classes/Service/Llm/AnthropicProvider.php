<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Llm;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Anthropic Messages API provider. Differs from OpenAI in two notable ways:
 *  - `system` is a top-level field, not a message with role=system.
 *  - `max_tokens` is required (Anthropic rejects requests without it).
 */
final class AnthropicProvider implements LlmProviderInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const DEFAULT_BASE_URL = 'https://api.anthropic.com';
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_MAX_TOKENS = 1024;

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function complete(array $messages, array $options): string
    {
        $model = (string)($options['model'] ?? '');
        if ($model === '') {
            throw new LlmException('Anthropic: model is required');
        }
        $apiKey = (string)($options['apiKey'] ?? '');
        if ($apiKey === '') {
            throw new LlmException('Anthropic: apiKey is required');
        }
        $baseUrl = rtrim((string)($options['url'] ?? self::DEFAULT_BASE_URL), '/');

        // Split system out of the message list; Anthropic wants it as a
        // separate top-level field. Multiple system messages get joined.
        $systemParts = [];
        $userMessages = [];
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $systemParts[] = (string)($msg['content'] ?? '');
                continue;
            }
            $userMessages[] = $msg;
        }

        $body = [
            'model' => $model,
            'messages' => array_values($userMessages),
            'max_tokens' => (int)($options['maxTokens'] ?? self::DEFAULT_MAX_TOKENS),
            'temperature' => (float)($options['temperature'] ?? 0.2),
        ];
        if ($systemParts !== []) {
            $body['system'] = implode("\n\n", $systemParts);
        }

        try {
            $response = $this->requestFactory->request(
                $baseUrl . '/v1/messages',
                'POST',
                [
                    'headers' => [
                        'x-api-key' => $apiKey,
                        'anthropic-version' => self::API_VERSION,
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'timeout' => 60,
                ],
            );
        } catch (\Throwable $e) {
            throw new LlmException('Anthropic: request failed: ' . $e->getMessage(), 0, $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw new LlmException(sprintf(
                'Anthropic: HTTP %d — %s',
                $response->getStatusCode(),
                substr((string)$response->getBody(), 0, 500),
            ));
        }

        try {
            $payload = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LlmException('Anthropic: malformed JSON response', 0, $e);
        }

        // content is an array of blocks; concatenate all text blocks.
        $blocks = is_array($payload['content'] ?? null) ? $payload['content'] : [];
        $text = '';
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }
        if ($text === '') {
            throw new LlmException('Anthropic: no text in response');
        }
        return $text;
    }
}
