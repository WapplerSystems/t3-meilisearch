<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Llm;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Ollama chat completion provider. Local self-hosted LLMs, no API key.
 * URL is required (e.g. http://ollama:11434).
 */
final class OllamaProvider implements LlmProviderInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    public function name(): string
    {
        return 'ollama';
    }

    public function complete(array $messages, array $options): string
    {
        $model = (string)($options['model'] ?? '');
        if ($model === '') {
            throw new LlmException('Ollama: model is required');
        }
        $url = rtrim((string)($options['url'] ?? ''), '/');
        if ($url === '') {
            throw new LlmException('Ollama: url is required');
        }

        $body = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'options' => [
                'temperature' => (float)($options['temperature'] ?? 0.2),
            ],
        ];
        if (isset($options['maxTokens'])) {
            // Ollama uses num_predict in the inner options block.
            $body['options']['num_predict'] = (int)$options['maxTokens'];
        }

        try {
            $response = $this->requestFactory->request(
                $url . '/api/chat',
                'POST',
                [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    // Local model inference can be slow on first warmup.
                    'timeout' => 180,
                ],
            );
        } catch (\Throwable $e) {
            throw new LlmException('Ollama: request failed: ' . $e->getMessage(), 0, $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw new LlmException(sprintf(
                'Ollama: HTTP %d — %s',
                $response->getStatusCode(),
                substr((string)$response->getBody(), 0, 500),
            ));
        }

        try {
            $payload = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LlmException('Ollama: malformed JSON response', 0, $e);
        }

        $content = $payload['message']['content'] ?? null;
        if (!is_string($content) || $content === '') {
            throw new LlmException('Ollama: no content in response');
        }
        return $content;
    }

    public function streamComplete(array $messages, array $options): iterable
    {
        $model = (string)($options['model'] ?? '');
        if ($model === '') {
            throw new LlmException('Ollama: model is required');
        }
        $url = rtrim((string)($options['url'] ?? ''), '/');
        if ($url === '') {
            throw new LlmException('Ollama: url is required');
        }

        $body = [
            'model' => $model,
            'messages' => $messages,
            // Ollama defaults to stream=true; we set it explicitly for clarity.
            'stream' => true,
            'options' => [
                'temperature' => (float)($options['temperature'] ?? 0.2),
            ],
        ];
        if (isset($options['maxTokens'])) {
            $body['options']['num_predict'] = (int)$options['maxTokens'];
        }

        try {
            $response = $this->requestFactory->request(
                $url . '/api/chat',
                'POST',
                [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'stream' => true,
                    'timeout' => 300,
                ],
            );
        } catch (\Throwable $e) {
            throw new LlmException('Ollama: request failed: ' . $e->getMessage(), 0, $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw new LlmException(sprintf(
                'Ollama: HTTP %d — %s',
                $response->getStatusCode(),
                substr((string)$response->getBody(), 0, 500),
            ));
        }

        // Ollama streams newline-delimited JSON: one complete JSON object
        // per line, with `done: true` on the final line.
        $body = $response->getBody();
        $buffer = '';
        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                usleep(10_000);
                continue;
            }
            $buffer .= $chunk;
            while (($newlineAt = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newlineAt));
                $buffer = substr($buffer, $newlineAt + 1);
                if ($line === '') {
                    continue;
                }
                try {
                    $event = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                $delta = $event['message']['content'] ?? null;
                if (is_string($delta) && $delta !== '') {
                    yield $delta;
                }
                if (($event['done'] ?? false) === true) {
                    return;
                }
            }
        }
    }
}
