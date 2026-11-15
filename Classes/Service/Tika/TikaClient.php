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

    /**
     * @param array{ocrLanguage?: string, ocrStrategy?: string} $ocr
     *     Pass an empty array to suppress all OCR-related headers — the
     *     base `apache/tika:*` image (no Tesseract) rejects them with
     *     HTTP 400, so the caller (TextExtractor) only fills this map
     *     when the operator explicitly opts in via
     *     `meilisearch.tika.ocrEnabled=true`.
     */
    public function extractText(
        string $tikaBaseUrl,
        string $fileContents,
        string $mimeType,
        int $timeout,
        array $ocr = [],
    ): ExtractionResult {
        return $this->request($tikaBaseUrl, $fileContents, $mimeType, $timeout, $ocr, 'text/plain');
    }

    /**
     * Text per page. Tika's XHTML output wraps every PDF page in
     * `<div class="page">`; the plain-text endpoint loses those boundaries,
     * and with them any chance to cite "page 14" of a 150-page manual.
     *
     * Formats without pages (DOCX, TXT, …) come back as one pseudo-page, so
     * callers never have to tell the two apart. Empty pages are kept as ''
     * so a list index always equals page number − 1.
     *
     * @param array{ocrLanguage?: string, ocrStrategy?: string} $ocr
     */
    public function extractPages(
        string $tikaBaseUrl,
        string $fileContents,
        string $mimeType,
        int $timeout,
        array $ocr = [],
    ): ExtractionResult {
        $result = $this->request($tikaBaseUrl, $fileContents, $mimeType, $timeout, $ocr, 'text/html');
        if ($result->status !== ExtractionResult::SUCCESS) {
            return $result;
        }
        $pages = self::pagesFromXhtml($result->text);
        if (implode('', $pages) === '') {
            return ExtractionResult::skipped('Tika returned no page text');
        }
        return ExtractionResult::pages($pages);
    }

    /**
     * @return list<string>
     */
    public static function pagesFromXhtml(string $xhtml): array
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $xhtml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return [];
        }
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " page ")]');
        if ($nodes === false || $nodes->length === 0) {
            $body = $dom->getElementsByTagName('body')->item(0);
            return [self::nodeText($body ?? $dom)];
        }
        $pages = [];
        foreach ($nodes as $node) {
            $pages[] = self::nodeText($node);
        }
        return $pages;
    }

    /**
     * Block elements become line breaks, so table rows and list items do not
     * run into one another; runs of blanks collapse to one.
     */
    private static function nodeText(\DOMNode $node): string
    {
        $lines = [];
        $current = '';
        $walk = static function (\DOMNode $n) use (&$walk, &$lines, &$current): void {
            foreach ($n->childNodes as $child) {
                if ($child instanceof \DOMText) {
                    $current .= $child->nodeValue;
                    continue;
                }
                if (!$child instanceof \DOMElement) {
                    continue;
                }
                $block = in_array(strtolower($child->tagName), ['p', 'div', 'li', 'tr', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table'], true);
                if ($block) {
                    $lines[] = $current;
                    $current = '';
                }
                $walk($child);
                if ($block) {
                    $lines[] = $current;
                    $current = '';
                } elseif (in_array(strtolower($child->tagName), ['td', 'th'], true)) {
                    $current .= ' | ';
                }
            }
        };
        $walk($node);
        $lines[] = $current;
        $clean = [];
        foreach ($lines as $line) {
            $line = trim((string)preg_replace('/[^\S\n]+/u', ' ', $line), " |\t\n\r\0\x0B");
            if ($line !== '') {
                $clean[] = $line;
            }
        }
        return implode("\n", $clean);
    }

    /**
     * @param array{ocrLanguage?: string, ocrStrategy?: string} $ocr
     */
    private function request(
        string $tikaBaseUrl,
        string $fileContents,
        string $mimeType,
        int $timeout,
        array $ocr,
        string $accept,
    ): ExtractionResult {
        $tikaBaseUrl = rtrim($tikaBaseUrl, '/');
        if ($tikaBaseUrl === '') {
            return ExtractionResult::skipped('Tika URL not configured');
        }

        $headers = [
            'Accept' => $accept,
            'Content-Type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
        ];
        $lang = trim((string)($ocr['ocrLanguage'] ?? ''));
        if ($lang !== '') {
            // X-Tika-OCRLanguage, no hyphen: Tika 3 rejects the hyphenated
            // name as "an invalid X-Tika-OCR header" (HTTP 400), so every
            // OCR request failed and scanned PDFs stayed out of the index.
            $headers['X-Tika-OCRLanguage'] = $lang;
        }
        $strategy = trim((string)($ocr['ocrStrategy'] ?? ''));
        if ($strategy !== '') {
            $headers['X-Tika-PDFOcrStrategy'] = $strategy;
        }

        try {
            $response = $this->requestFactory->request(
                $tikaBaseUrl . '/tika',
                'PUT',
                [
                    'body' => $fileContents,
                    'headers' => $headers,
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
