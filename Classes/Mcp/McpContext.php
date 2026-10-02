<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Mcp;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * What a tool call runs against: the site the endpoint was called on, the
 * authenticated client and — for writing tools — the backend user the
 * client acts as.
 */
final class McpContext
{
    private ?BackendUserAuthentication $backendUser = null;

    public function __construct(
        public readonly Site $site,
        public readonly McpClient $client,
        public readonly ServerRequestInterface $request,
    ) {}

    /**
     * The site language for an optional `language` argument: a language id
     * or a two-letter ISO code ("de", "en"). Missing → default language.
     */
    public function language(mixed $value): SiteLanguage
    {
        if ($value === null || $value === '') {
            return $this->site->getDefaultLanguage();
        }
        foreach ($this->site->getLanguages() as $language) {
            if ((is_int($value) || ctype_digit((string)$value)) && $language->getLanguageId() === (int)$value) {
                return $language;
            }
            if (is_string($value) && strtolower($language->getLocale()->getLanguageCode()) === strtolower($value)) {
                return $language;
            }
        }
        throw new McpToolException(sprintf(
            'Unknown language "%s". Available: %s',
            (string)$value,
            implode(', ', array_map(
                static fn(SiteLanguage $l): string => $l->getLanguageId() . '=' . $l->getLocale()->getLanguageCode(),
                $this->site->getLanguages(),
            )),
        ));
    }

    /**
     * The backend user writes run as, set up the way TYPO3 does for CLI
     * commands: an authenticated user object without a session, with its
     * groups, permissions and web mounts. DataHandler then applies exactly
     * the permissions that user has in the backend — the token grants the
     * scope, the backend user bounds what that scope can touch — and the
     * record history names who changed what.
     *
     * Disabled, deleted or expired backend users are rejected by the core
     * lookup itself.
     */
    public function backendUser(): BackendUserAuthentication
    {
        if ($this->backendUser instanceof BackendUserAuthentication) {
            return $this->backendUser;
        }
        if ($this->client->backendUserUid <= 0) {
            throw new McpToolException('This access has no backend user assigned and cannot change data.');
        }
        $user = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $user->setBeUserByUid($this->client->backendUserUid);
        if (!is_array($user->user) || $user->user === []) {
            throw new McpToolException('The backend user of this access is disabled or does not exist.');
        }
        $user->fetchGroupData();
        $this->backendUser = $user;

        // DataHandler and the indexing hook read the globals.
        $GLOBALS['BE_USER'] = $user;
        $GLOBALS['LANG'] ??= GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($user);

        return $user;
    }
}
