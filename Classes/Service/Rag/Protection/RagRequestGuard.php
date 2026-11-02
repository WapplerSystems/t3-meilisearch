<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag\Protection;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

/**
 * Decides whether a chat question may reach the model at all — before
 * retrieval, before the LLM, before anything is written to the protocol.
 *
 * Every question costs two model calls (rewrite and answer) and the chat is
 * public. Without a limit, one script can ask thousands of questions in an
 * hour and the bill follows. The checks, cheapest first:
 *
 *  - not a browser: the request does not come from a page of this site
 *    (Sec-Fetch-Site, or Origin/Referer on older browsers), or the user agent
 *    is a crawler or an HTTP library;
 *  - too long: a question beyond maxQuestionLength characters (prompt size);
 *  - per client: questions per address in a sliding window and per day;
 *  - per conversation: questions per conversation and day;
 *  - per site: questions per day across all visitors — the cost cap, also
 *    against many addresses at once.
 *
 * The client is the remote address, an IPv6 address by its /64 network (one
 * connection usually owns the whole prefix), hashed with the installation's
 * encryption key. Only that hash is kept, in the cache ws_meilisearch_ratelimit,
 * for at most the longest window — no address is stored.
 *
 * Off unless meilisearch.rag.protection.enabled. A limit of 0 is no limit.
 */
final class RagRequestGuard implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const NOT_A_BROWSER = 'browser';
    public const TOO_LONG = 'length';
    public const CLIENT_LIMIT = 'client';
    public const CONVERSATION_LIMIT = 'conversation';
    public const SITE_LIMIT = 'site';

    private const CACHE = 'ws_meilisearch_ratelimit';

    private ?FrontendInterface $cache = null;

    public function __construct(
        private readonly CacheManager $cacheManager,
    ) {}

    /**
     * Null when the question may go ahead, else one of the reason constants.
     * Counts the question against every limit it passed.
     */
    public function refuse(Site $site, ServerRequestInterface $request, string $question, string $conversationId = ''): ?string
    {
        $settings = $site->getSettings();
        if (!(bool)$settings->get('meilisearch.rag.protection.enabled', false)) {
            return null;
        }
        if (!$this->comesFromBrowser($site, $request)) {
            return self::NOT_A_BROWSER;
        }
        $maxLength = (int)$settings->get('meilisearch.rag.protection.maxQuestionLength', 0);
        if ($maxLength > 0 && mb_strlen($question) > $maxLength) {
            return self::TOO_LONG;
        }

        $client = $this->clientKey($request);
        $checks = [
            [self::CLIENT_LIMIT, 'client', $client, 'sliding_window', $this->window($settings, 'meilisearch.rag.protection.perClient')],
            [self::CLIENT_LIMIT, 'client-day', $client, 'fixed_window', [(int)$settings->get('meilisearch.rag.protection.perClientDaily', 0), '1 day']],
            [self::CONVERSATION_LIMIT, 'conversation', $conversationId, 'fixed_window', [(int)$settings->get('meilisearch.rag.protection.perConversation', 0), '1 day']],
            [self::SITE_LIMIT, 'site', '', 'fixed_window', [(int)$settings->get('meilisearch.rag.protection.perSiteDaily', 0), '1 day']],
        ];
        foreach ($checks as [$reason, $name, $key, $policy, [$limit, $interval]]) {
            if ($limit <= 0 || ($name === 'conversation' && $key === '')) {
                continue;
            }
            $factory = new RateLimiterFactory([
                'id' => 'ws-meilisearch-rag-' . $site->getIdentifier() . '-' . $name,
                'policy' => $policy,
                'limit' => $limit,
                'interval' => $interval,
            ], new ProtectionStorage($this->cache()));
            if (!$factory->create($key)->consume()->isAccepted()) {
                if ($reason === self::SITE_LIMIT) {
                    $this->warnOncePerDay($site, $limit);
                }

                return $reason;
            }
        }

        return null;
    }

    /**
     * A page of this site asked: the chat's EventSource sends
     * "Sec-Fetch-Site: same-origin". Browsers without Fetch Metadata send an
     * Origin or a Referer of this host. A script has to fake both — the
     * limits stay in place for the one that does.
     */
    private function comesFromBrowser(Site $site, ServerRequestInterface $request): bool
    {
        $settings = $site->getSettings();
        $agent = $request->getHeaderLine('User-Agent');
        if ($agent === '') {
            return false;
        }
        foreach ((array)$settings->get('meilisearch.rag.protection.blockedUserAgents', []) as $pattern) {
            $pattern = trim((string)$pattern);
            if ($pattern !== '' && mb_stripos($agent, $pattern) !== false) {
                return false;
            }
        }
        if (!(bool)$settings->get('meilisearch.rag.protection.requireSameOrigin', true)) {
            return true;
        }
        $fetchSite = strtolower($request->getHeaderLine('Sec-Fetch-Site'));
        if ($fetchSite !== '') {
            return $fetchSite === 'same-origin';
        }
        $host = strtolower($request->getUri()->getHost());
        foreach (['Origin', 'Referer'] as $header) {
            $value = $request->getHeaderLine($header);
            if ($value !== '') {
                return strtolower((string)parse_url($value, PHP_URL_HOST)) === $host;
            }
        }

        return false;
    }

    /**
     * "<limit>/<interval>", e.g. "20/10 minutes"; anything else is no limit.
     *
     * @return array{0:int,1:string}
     */
    private function window(SiteSettings $settings, string $key): array
    {
        if (preg_match('/^\s*(\d+)\s*\/\s*(\d+\s*[a-z]+)\s*$/i', (string)$settings->get($key, ''), $m) !== 1) {
            return [0, ''];
        }

        return [(int)$m[1], $m[2]];
    }

    private function clientKey(ServerRequestInterface $request): string
    {
        $normalized = $request->getAttribute('normalizedParams');
        $address = $normalized instanceof NormalizedParams
            ? $normalized->getRemoteAddress()
            : (string)($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $packed = @inet_pton($address);
        if ($packed !== false && strlen($packed) === 16) {
            $address = bin2hex(substr($packed, 0, 8)) . '::/64';
        }

        return hash_hmac('sha256', $address, (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? ''));
    }

    /**
     * The site cap is the one limit someone has to know about: the chat is
     * off for everybody until the day ends. Logged once per day, not per
     * refused question — under an attack that would be thousands of lines.
     */
    private function warnOncePerDay(Site $site, int $limit): void
    {
        $flag = 'site-cap-' . sha1($site->getIdentifier() . date('Y-m-d'));
        if ($this->cache()->has($flag)) {
            return;
        }
        $this->cache()->set($flag, 1, [], 86400);
        $this->logger?->warning('RAG daily question cap of {limit} reached on site {site}; the chat answers with the unavailable message until the window ends', [
            'limit' => $limit,
            'site' => $site->getIdentifier(),
        ]);
    }

    private function cache(): FrontendInterface
    {
        return $this->cache ??= $this->cacheManager->getCache(self::CACHE);
    }
}
