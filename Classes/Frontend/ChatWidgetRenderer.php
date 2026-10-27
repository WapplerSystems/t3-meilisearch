<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Frontend;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Attribute\AsAllowedCallable;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * Renders the anchor element of the floating chat widget (USER cObject, see
 * setup.typoscript). ChatWidget.js builds launcher, teaser and panel from it.
 *
 * Everything that makes the widget a particular site's assistant — name, icon,
 * texts, greeting, example questions, the note under the input — comes from
 * the meilisearch.rag.chatWidget.* site settings and travels as one JSON
 * attribute, so the extension ships no branding of its own. Texts may be
 * LLL:EXT:… references and are resolved here in the page language, because
 * site settings cannot be translated. Example questions carry their language
 * in the entry ("<group>:<lang>:<question>"), so an editor maintains them in
 * the site settings module without touching code.
 *
 * Rendered per page and language into the page cache: the settings only
 * change with a cache flush, which a settings save triggers anyway.
 */
final class ChatWidgetRenderer
{
    private const LLL = 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang.xlf:';
    private const PREFIX = 'meilisearch.rag.chatWidget.';

    private ?ContentObjectRenderer $cObj = null;

    public function __construct(
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function setContentObjectRenderer(ContentObjectRenderer $cObj): void
    {
        $this->cObj = $cObj;
    }

    /**
     * @param array<string, mixed> $conf pageUid, url, embedTypeNum (from the constants)
     */
    #[AsAllowedCallable]
    public function render(string $content, array $conf, ServerRequestInterface $request): string
    {
        $site = $request->getAttribute('site');
        $language = $request->getAttribute('language');
        if (!$site instanceof Site || $this->cObj === null) {
            return '';
        }
        $url = $this->targetUrl($conf);
        if ($url === '') {
            return '';
        }
        $lang = $language instanceof SiteLanguage
            ? $this->languageServiceFactory->createFromSiteLanguage($language)
            : $this->languageServiceFactory->create('default');
        $langCode = $language instanceof SiteLanguage ? strtolower($language->getLocale()->getLanguageCode()) : 'en';

        $label = $this->text($site, $lang, 'label');
        if ($label === '') {
            $label = 'Chat';
        }
        $config = [
            'title' => $this->text($site, $lang, 'title') ?: $label,
            'subtitle' => $this->text($site, $lang, 'subtitle'),
            'badge' => $this->text($site, $lang, 'badge'),
            'launcherText' => $this->text($site, $lang, 'launcherText'),
            'icon' => $this->iconUrl((string)$site->getSettings()->get(self::PREFIX . 'icon', '')),
            'teaser' => [
                'title' => $this->text($site, $lang, 'teaserTitle'),
                'text' => $this->text($site, $lang, 'teaserText'),
                'delay' => max(0, (int)$site->getSettings()->get(self::PREFIX . 'teaserDelay', 4)),
            ],
            'expandable' => (bool)$site->getSettings()->get(self::PREFIX . 'expandable', true),
            'greeting' => [
                'title' => $this->text($site, $lang, 'greetingTitle'),
                'text' => $this->text($site, $lang, 'greetingText'),
                'hint' => $this->text($site, $lang, 'greetingHint'),
                'note' => $this->text($site, $lang, 'greetingNote'),
                'groups' => $this->exampleGroups($site, $langCode),
            ],
            'placeholder' => $this->text($site, $lang, 'placeholder'),
            'disclaimer' => $this->disclaimer($this->text($site, $lang, 'disclaimer')),
            'labels' => [
                'newChat' => $lang->sL(self::LLL . 'chat.widget.newChat'),
                'expand' => $lang->sL(self::LLL . 'chat.widget.expand'),
                'shrink' => $lang->sL(self::LLL . 'chat.widget.shrink'),
                'close' => $lang->sL(self::LLL . 'chat.widget.close'),
                'closeTeaser' => $lang->sL(self::LLL . 'chat.widget.closeTeaser'),
                'loading' => $lang->sL(self::LLL . 'chat.widget.loading'),
                'error' => $lang->sL(self::LLL . 'chat.widget.error'),
                'send' => $lang->sL(self::LLL . 'chat.widget.send'),
            ],
        ];

        return '<div id="ws-meilisearch-chat-widget"'
            . ' data-url="' . htmlspecialchars($url) . '"'
            . ' data-label="' . htmlspecialchars($label) . '"'
            . ' data-config="' . htmlspecialchars((string)json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"'
            . '></div>';
    }

    /**
     * pageUid > 0: TypoLink, so the URL follows the language and slug renames.
     * Otherwise the legacy hard-coded URL.
     *
     * @param array<string, mixed> $conf
     */
    private function targetUrl(array $conf): string
    {
        $typeNum = (int)($conf['embedTypeNum'] ?? 0);
        $pageUid = (int)($conf['pageUid'] ?? 0);
        if ($pageUid > 0) {
            return $this->cObj->typoLink_URL(['parameter' => $pageUid . ',' . $typeNum]);
        }
        $url = trim((string)($conf['url'] ?? ''));

        return $url === '' ? '' : $url . '?type=' . $typeNum;
    }

    private function text(Site $site, LanguageService $lang, string $key): string
    {
        $value = trim((string)$site->getSettings()->get(self::PREFIX . $key, ''));

        return str_starts_with($value, 'LLL:') ? trim($lang->sL($value)) : $value;
    }

    private function iconUrl(string $icon): string
    {
        $icon = trim($icon);
        if ($icon === '') {
            return '';
        }
        if (str_starts_with($icon, 'EXT:')) {
            return (string)PathUtility::getPublicResourceWebPath($icon);
        }

        return $icon;
    }

    /**
     * Groups in the order of their first entry; only questions in the page
     * language.
     *
     * @return list<array{key: string, label: string, questions: list<string>}>
     */
    private function exampleGroups(Site $site, string $langCode): array
    {
        $labels = [];
        foreach ($this->entries($site, 'exampleGroups') as [$group, $lang, $text]) {
            if ($lang === $langCode) {
                $labels[$group] = $text;
            }
        }
        $groups = [];
        foreach ($this->entries($site, 'examples') as [$group, $lang, $text]) {
            if ($lang !== $langCode) {
                continue;
            }
            $groups[$group] ??= ['key' => $group, 'label' => $labels[$group] ?? '', 'questions' => []];
            $groups[$group]['questions'][] = $text;
        }

        return array_values($groups);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> [group, lang, text]
     */
    private function entries(Site $site, string $key): array
    {
        $raw = $site->getSettings()->get(self::PREFIX . $key, []);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (preg_match('/^\s*([a-z0-9_-]+)\s*:\s*([a-z]{2})\s*:\s*(.+?)\s*$/i', (string)$entry, $m) === 1) {
                $out[] = [strtolower($m[1]), strtolower($m[2]), $m[3]];
            }
        }

        return $out;
    }

    /**
     * Splits "… [text](link) …" into the parts around one link; the link is
     * resolved through TypoLink so t3:// references, URLs and mail addresses
     * all work.
     *
     * @return array{before: string, linkText: string, href: string, after: string}|null
     */
    private function disclaimer(string $text): ?array
    {
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(.*?)\[([^\]]+)\]\(([^)\s]+)\)(.*)$/s', $text, $m) !== 1) {
            return ['before' => $text, 'linkText' => '', 'href' => '', 'after' => ''];
        }
        $href = $this->cObj->typoLink_URL(['parameter' => $m[3]]);

        return $href === ''
            ? ['before' => $m[1] . $m[2] . $m[4], 'linkText' => '', 'href' => '', 'after' => '']
            : ['before' => $m[1], 'linkText' => $m[2], 'href' => $href, 'after' => $m[4]];
    }
}
