<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use LanguageDetection\Language;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Detects the *content* language of a document, independent of the
 * TYPO3 site-language overlay it was indexed under. Used to populate
 * a per-doc `contentLanguage` field — a German PDF whose ext:index
 * row got copied into a fr-FR language overlay still ends up with
 * contentLanguage="de", so the search filter can hide it from
 * French-locale visitors.
 *
 * Backed by patrickschur/language-detection (n-gram + chi-squared
 * scoring). Detection results are cached in-memory by content hash
 * to avoid re-running the n-gram pass on every save of the same
 * file across language overlays.
 *
 * Returns a lowercase ISO 639-1 code on success ("de", "en", "fr",
 * …) or an empty string when:
 *  - the text is too short for confident detection
 *  - the configured allow-list excludes every detected candidate
 *  - the library throws
 *
 * Callers should treat the empty string as "unknown" and refrain
 * from adding it to a filter expression.
 */
final class LanguageDetector implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Below this many characters detection becomes noisy ("LINEAR"
     * alone matches a dozen languages). Returns "" for shorter input
     * so the indexer doesn't poison the corpus with random codes.
     */
    private const MIN_CHARS = 80;

    /**
     * Detection cache keyed by sha1(text). Same content arriving via
     * 5 language overlays runs the n-gram pass once.
     *
     * @var array<string,string>
     */
    private array $cache = [];

    private ?Language $detector = null;

    public function detect(Site $site, string $text): string
    {
        $text = trim($text);
        if (\mb_strlen($text) < self::MIN_CHARS) {
            return '';
        }
        $key = sha1($text);
        if (\array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        try {
            $detector = $this->detector ??= new Language();
            // top-1 result, just the ISO code; the library returns
            // codes like "de", "en-US", "fr" — collapse to the base
            // ISO 639-1 since we don't need region granularity.
            $best = $detector->detect($text)->bestResults()->close();
            $code = '';
            if (\is_array($best) && $best !== []) {
                $code = (string)\array_key_first($best);
            }
            if ($code !== '' && \str_contains($code, '-')) {
                $code = \strtolower(\strstr($code, '-', true));
            }
            $code = \strtolower($code);
            // Optional allow-list — a site can declare which language
            // codes are legitimate (its configured languages plus any
            // partner sites' codes) so a fluke detection of, say,
            // Klingon doesn't end up in the index.
            $rawAllow = $site->getSettings()->get('meilisearch.indexing.contentLanguageAllowList', null);
            // Defensive parse: typed stringlist comes through as array,
            // but a list defined only flat in settings.yaml without a
            // typed identifier comes through as the raw value (string
            // "[de,en,…]" or comma-separated). Handle both shapes so
            // operators using either declaration style get the same
            // behaviour.
            $allow = [];
            if (\is_array($rawAllow)) {
                $allow = $rawAllow;
            } elseif (\is_string($rawAllow) && $rawAllow !== '') {
                $trimmed = \trim($rawAllow, " \t\n\r\0\x0B[]");
                $allow = \array_filter(\array_map('trim', \explode(',', $trimmed)));
            }
            if ($allow !== [] && !\in_array($code, \array_map('strtolower', \array_map('strval', $allow)), true)) {
                $code = '';
            }
            $this->cache[$key] = $code;
            return $code;
        } catch (\Throwable $e) {
            $this->logger?->info(
                'LanguageDetector failed for site {site}: {msg}',
                ['site' => $site->getIdentifier(), 'msg' => $e->getMessage()],
            );
            $this->cache[$key] = '';
            return '';
        }
    }
}
