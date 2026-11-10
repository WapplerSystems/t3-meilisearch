<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * The reasons a visitor can tick after "not helpful"
 * (meilisearch.rag.feedback.reasons, entries "<key>:<lang>:<label>", same
 * shape as meilisearch.rag.fixedReplies). The key is what the protocol
 * stores and the backend filters by; the label is what the visitor sees.
 * Unknown language: the English label, else the first one of the key.
 */
final class FeedbackReasons
{
    /**
     * @return array<string, array<string, string>> key → language → label, in configured order
     */
    public static function all(Site $site): array
    {
        $reasons = [];
        foreach ((array)$site->getSettings()->get('meilisearch.rag.feedback.reasons', []) as $entry) {
            $parts = array_map('trim', explode(':', (string)$entry, 3));
            if (count($parts) !== 3 || $parts[2] === '' || preg_match('/^[a-z0-9_-]{1,40}$/', strtolower($parts[0])) !== 1) {
                continue;
            }
            $reasons[strtolower($parts[0])][strtolower($parts[1])] = $parts[2];
        }

        return $reasons;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function forLanguage(Site $site, string $languageCode): array
    {
        $languageCode = strtolower($languageCode);
        $list = [];
        foreach (self::all($site) as $key => $labels) {
            $list[] = ['key' => $key, 'label' => $labels[$languageCode] ?? $labels['en'] ?? (string)reset($labels)];
        }

        return $list;
    }

    /**
     * Keeps the configured keys of a submitted selection, in configured order.
     *
     * @param list<string> $submitted
     * @return list<string>
     */
    public static function filter(Site $site, array $submitted): array
    {
        $submitted = array_map(static fn (mixed $k): string => strtolower(trim((string)$k)), $submitted);

        return array_values(array_filter(
            array_keys(self::all($site)),
            static fn (string $key): bool => in_array($key, $submitted, true),
        ));
    }

    /**
     * Characters of free text accepted after "not helpful"; 0 = no text field.
     */
    public static function commentMaxLength(Site $site): int
    {
        return max(0, min(2000, (int)$site->getSettings()->get('meilisearch.rag.feedback.commentMaxLength', 0)));
    }
}
