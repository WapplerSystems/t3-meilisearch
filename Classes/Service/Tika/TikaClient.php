<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Tika;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Thin HTTP wrapper around the Apache Tika Server REST API.
 *
 * Tika exposes one canonical "give me the text" endpoint:
 *   PUT /tika   body=<file bytes>   Accept: text/plain
 *
 * We send the raw file bytes (Tika handles content-type sniffing internally
 * when we provide a Content-Type hint). Everything else — caching, mime
 * filter, size check — lives in TextExtractor.
 */
final class TikaClient implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    public function extractText(string $tikaBaseUrl, string $fileContents, string $mimeType, int $timeout): ExtractionResult
    {
        $tikaBaseUrl = rtrim($tikaBaseUrl, '/');
        if ($tikaBaseUrl === '') {
            return ExtractionResult::skipped('Tika URL not configured');
        }

        try {
            $response = $this->requestFactory->request(
                $tikaBaseUrl . '/tika',
                'PUT',
                [
                    'body' => $fileContents,
                    'headers' => [
                        'Accept' => 'text/plain',
                        'Content-Type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
                    ],
                    'timeout' => $timeout,
                ],
            );
        } catch (\Throwable $e) {
            $this->logger?->warning('Tika request failed: {message}', [
                'message' => $e->getMessage(),
                'mime' => $mimeType,
                'exception' => $e,
            ]);
            return ExtractionResult::failed('Request failed: ' . $e->getMessage());
        }

        $status = $response->getStatusCode();
        if ($status === 204 || $status === 422) {
            // 204: empty output (e.g. image with no text)
            // 422: Tika could not parse — treated as skipped, not a hard failure
            return ExtractionResult::skipped('Tika returned ' . $status);
        }
        if ($status !== 200) {
            return ExtractionResult::failed('Tika HTTP ' . $status);
        }

        $text = trim((string)$response->getBody());
        if ($text === '') {
            return ExtractionResult::skipped('Tika returned empty text');
        }
        return ExtractionResult::success($text);
    }
}
