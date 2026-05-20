<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Llm;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * OpenAI chat completions provider. Also serves as the base for any
 * OpenAI-compatible REST endpoint (vLLM, LM Studio, Together, Groq, …) via
 * RestProvider which overrides the base URL.
 */
class OpenAiProvider implements LlmProviderInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected const DEFAULT_BASE_URL = 'https://api.openai.com';

    public function __construct(
        protected readonly RequestFactory $requestFactory,
    ) {}

    public function name(): string
    {
        return 'openAi';
    }

    public function complete(array $messages, array $options): string
    {
        $model = (string)($options['model'] ?? '');
        if ($model === '') {
            throw new LlmException('OpenAI: model is required');
        }
        $apiKey = (string)($options['apiKey'] ?? '');
        if ($apiKey === '') {
            throw new LlmException('OpenAI: apiKey is required');
        }
        $baseUrl = rtrim((string)($options['url'] ?? static::DEFAULT_BASE_URL), '/');

        $body = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => (float)($options['temperature'] ?? 0.2),
        ];
        if (isset($options['maxTokens'])) {
            $body['max_tokens'] = (int)$options['maxTokens'];
        }

        try {
            $response = $this->requestFactory->request(
                $baseUrl . '/v1/chat/completions',
                'POST',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'timeout' => 60,
                ],
            );
        } catch (\Throwable $e) {
            throw new LlmException(static::class . ': request failed: ' . $e->getMessage(), 0, $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw new LlmException(sprintf(
                '%s: HTTP %d — %s',
                static::class,
                $response->getStatusCode(),
                substr((string)$response->getBody(), 0, 500),
            ));
        }

        try {
            $payload = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LlmException(static::class . ': malformed JSON response', 0, $e);
        }

        $content = $payload['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || $content === '') {
            throw new LlmException(static::class . ': no content in response');
        }
        return $content;
    }

    public function streamComplete(array $messages, array $options): iterable
    {
        $model = (string)($options['model'] ?? '');
        if ($model === '') {
            throw new LlmException(static::class . ': model is required');
        }
        $apiKey = (string)($options['apiKey'] ?? '');
        if ($apiKey === '') {
            throw new LlmException(static::class . ': apiKey is required');
        }
        $baseUrl = rtrim((string)($options['url'] ?? static::DEFAULT_BASE_URL), '/');

        $body = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => (float)($options['temperature'] ?? 0.2),
            'stream' => true,
        ];
        if (isset($options['maxTokens'])) {
            $body['max_tokens'] = (int)$options['maxTokens'];
        }

        try {
            $response = $this->requestFactory->request(
                $baseUrl . '/v1/chat/completions',
                'POST',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
                        'Accept' => 'text/event-stream',
                    ],
                    'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'stream' => true,
                    'timeout' => 120,
                ],
            );
        } catch (\Throwable $e) {
            throw new LlmException(static::class . ': request failed: ' . $e->getMessage(), 0, $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw new LlmException(sprintf(
                '%s: HTTP %d — %s',
                static::class,
                $response->getStatusCode(),
                substr((string)$response->getBody(), 0, 500),
            ));
        }

        yield from $this->parseSseDeltas($response->getBody());
    }

    /**
     * OpenAI-style SSE: each event is a `data: {json}` line, separated by
     * blank lines. The JSON payload's `choices[0].delta.content` carries
     * the next text fragment. The stream terminates with `data: [DONE]`.
     *
     * @return iterable<string>
     */
    protected function parseSseDeltas(\Psr\Http\Message\StreamInterface $body): iterable
    {
        $buffer = '';
        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                // Avoid a tight CPU-spin when the upstream pauses mid-stream.
                usleep(10_000);
                continue;
            }
            $buffer .= $chunk;
            while (($newlineAt = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $newlineAt), "\r");
                $buffer = substr($buffer, $newlineAt + 1);
                if ($line === '' || !str_starts_with($line, 'data: ')) {
                    continue;
                }
                $payload = substr($line, 6);
                if ($payload === '[DONE]') {
                    return;
                }
                try {
                    $event = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                $delta = $event['choices'][0]['delta']['content'] ?? null;
                if (is_string($delta) && $delta !== '') {
                    yield $delta;
                }
            }
        }
    }
}
