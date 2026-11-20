<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag\Protocol;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Where a chat turn came from, for the protocol: the page the chat was opened
 * on and whether the turn is internal traffic of the site owner.
 *
 * LINEAR reviews the protocol daily and could not tell its own test questions
 * from visitors' (Gregor 18.11.2026). Internal is a logged-in backend user or
 * a client address in meilisearch.rag.protocol.internalNetworks (addresses
 * and CIDR ranges, as GeneralUtility::cmpIP() takes them). The page is the
 * path of the Referer on the site's own host — the chat endpoint itself is
 * not the page — without query or fragment, which may carry personal data.
 */
final class TrafficOrigin
{
    public const BACKEND = 'backend';
    public const NETWORK = 'network';

    public function __construct(
        private readonly Context $context,
    ) {}

    public function pageUrl(ServerRequestInterface $request): string
    {
        $referer = trim($request->getHeaderLine('Referer'));
        if ($referer === '') {
            return '';
        }
        $parts = parse_url($referer);
        if (!is_array($parts) || strcasecmp((string)($parts['host'] ?? ''), $request->getUri()->getHost()) !== 0) {
            return '';
        }

        return mb_substr((string)($parts['path'] ?? '/'), 0, 255);
    }

    public function internal(Site $site, ServerRequestInterface $request): string
    {
        try {
            if ((bool)$this->context->getPropertyFromAspect('backend.user', 'isLoggedIn', false)) {
                return self::BACKEND;
            }
        } catch (\Throwable) {
        }
        $networks = array_values(array_filter(array_map(
            static fn ($n): string => trim((string)$n),
            (array)$site->getSettings()->get('meilisearch.rag.protocol.internalNetworks', []),
        )));
        $ip = (string)($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $normalized = (string)$request->getAttribute('normalizedParams')?->getRemoteAddress();
        if ($normalized !== '') {
            $ip = $normalized;
        }

        return $networks !== [] && $ip !== '' && GeneralUtility::cmpIP($ip, implode(',', $networks)) ? self::NETWORK : '';
    }
}
