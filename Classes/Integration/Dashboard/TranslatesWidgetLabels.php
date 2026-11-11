<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Integration\Dashboard;

use TYPO3\CMS\Core\Localization\LanguageService;

trait TranslatesWidgetLabels
{
    private function label(string $key): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        $label = 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:' . $key;

        return $languageService instanceof LanguageService ? $languageService->sL($label) : $key;
    }

    /** List items are plain text in the widget; long questions break its layout. */
    private function shorten(string $text, int $length = 120): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
    }
}
