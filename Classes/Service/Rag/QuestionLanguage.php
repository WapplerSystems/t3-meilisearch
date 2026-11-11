<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

use LanguageDetection\Language;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Which site language a chat question is answered in — and so which
 * knowledge, citation cards and contact cards it gets — when the visitor
 * writes in another language than the page.
 *
 * Without it the page decides: an English question on a German page is
 * answered in English (the prompt follows the question) but from German
 * sources, with German videos and cards. With meilisearch.rag.questionLanguage
 * enabled, a question clearly in another site language switches the turn to
 * that language; its knowledgeLanguages entry applies as on its own pages
 * (PL → English knowledge). questionLanguage.otherLanguages are detected too
 * and go to questionLanguage.fallbackLanguageId, having no pages of their own.
 * Languages in questionLanguage.keep never switch (a German question on the
 * English pages stays there).
 *
 * Detection is deliberately cautious, because a wrong switch is worse than
 * none. Character n-grams, which work for documents, guess on a chat
 * question: "How do I manually add a supply line in a room?" scores English
 * 0.447 and Polish 0.424. Function words decide instead — "how", "do", "I"
 * are English and nothing else. A language wins with at least two of its own
 * function words and twice as many as any other candidate; a word in several
 * lists does not vote. Without such a vote the n-grams decide only by a wide
 * margin (another script, e.g. Cyrillic) and from minWords words. The
 * ignoreTerms (product names, which read as any language) are taken out
 * first. Short or mixed questions ("Fehler 126 beim Start Revit", "Room book
 * export Revit") keep the page language.
 *
 * The first question of a conversation that is clear enough decides for the
 * whole conversation, so the sources do not change between follow-ups.
 */
final class QuestionLanguage
{
    private const MIN_WORDS = 4;

    /** N-gram winner must lead the second by this share of its own score. */
    private const NGRAM_MARGIN = 0.3;

    /** Question and function words per language, lowercase. */
    private const FUNCTION_WORDS = [
        'en' => 'how what which where when why who can could does do did is are the a an my i you your it this that to of for with from and or not there should would will',
        'de' => 'wie was welche welcher welches wo wann warum wer kann können muss ist sind der die das den dem des ein eine einen einem ich mein meine sie mit und oder nicht für beim im ins vom zum zur auf gibt habe hat wird werden lässt',
        'nl' => 'hoe wat welke waar wanneer waarom wie kan kunnen is zijn de het een ik mijn u uw met en of niet voor bij van naar op er heb heeft wordt worden',
        'pl' => 'jak co który która które gdzie kiedy dlaczego czy mogę można jest są w we z ze i lub nie dla na do od się mój moja mam',
        'tr' => 'nasıl ne hangi nerede neden mı mi mu mü var yok bir ve veya değil için ile bu şu',
        'ru' => 'как что какой какая где когда почему можно ли это в на с и или не для у я мой есть',
        'fr' => 'comment quel quelle où quand pourquoi est sont le la les un une je mon ma et ou pas pour avec dans sur du des',
        'es' => 'cómo como qué cuál dónde cuándo por es son el la los las un una yo mi y o no para con en del',
        'it' => 'come cosa quale dove quando perché è sono il lo la gli un una io mio e o non per con nel della',
    ];

    /** @var array<string,string> */
    private array $cache = [];

    /**
     * Site language id for this turn, or null to keep the page language.
     */
    public function resolve(Site $site, string $question, Conversation $conversation, ?int $pageLanguageId): ?int
    {
        $settings = $site->getSettings();
        if (!(bool)$settings->get('meilisearch.rag.questionLanguage.enabled', false)) {
            return null;
        }
        $codes = [];
        foreach ($site->getLanguages() as $language) {
            $code = strtolower($language->getLocale()->getLanguageCode());
            $codes[$code] ??= $language->getLanguageId();
        }
        try {
            $page = $site->getLanguageById($pageLanguageId ?? 0);
        } catch (\InvalidArgumentException) {
            $page = $site->getDefaultLanguage();
        }
        $pageCode = strtolower($page->getLocale()->getLanguageCode());

        $others = array_map('strtolower', array_map('trim', self::stringList($settings->get('meilisearch.rag.questionLanguage.otherLanguages', []))));
        $candidates = array_values(array_unique(array_filter([...array_keys($codes), ...$others])));
        $questions = array_map(static fn (Turn $turn): string => $turn->question, $conversation->turns);
        $questions[] = $question;
        $detected = '';
        foreach ($questions as $text) {
            $detected = $this->detect($site, $text, $candidates);
            if ($detected !== '') {
                break;
            }
        }
        $keep = array_map('strtolower', array_map('trim', self::stringList($settings->get('meilisearch.rag.questionLanguage.keep', []))));
        if ($detected === '' || $detected === $pageCode || in_array($detected, $keep, true)) {
            return null;
        }
        if (isset($codes[$detected])) {
            return $codes[$detected];
        }
        $fallback = (int)$settings->get('meilisearch.rag.questionLanguage.fallbackLanguageId', -1);
        if ($fallback < 0 || $fallback === $page->getLanguageId()) {
            return null;
        }
        try {
            return $site->getLanguageById($fallback)->getLanguageId();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * ISO code of the question, '' when it is too short or not clear.
     *
     * @param list<string> $candidates
     */
    private function detect(Site $site, string $text, array $candidates): string
    {
        foreach (self::stringList($site->getSettings()->get('meilisearch.rag.questionLanguage.ignoreTerms', [])) as $term) {
            $term = trim($term);
            if ($term !== '') {
                $text = (string)preg_replace('/(?<!\pL)' . preg_quote($term, '/') . '(?!\pL)/iu', ' ', $text);
            }
        }
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        if ($text === '' || $candidates === []) {
            return '';
        }
        $key = sha1(implode(',', $candidates) . "\0" . $text);

        return $this->cache[$key] ??= $this->byFunctionWords($text, $candidates)
            ?: $this->byNgrams($text, $candidates, max(1, (int)$site->getSettings()->get('meilisearch.rag.questionLanguage.minWords', self::MIN_WORDS)));
    }

    /**
     * @param list<string> $candidates
     */
    private function byFunctionWords(string $text, array $candidates): string
    {
        $lists = [];
        $seen = [];
        foreach ($candidates as $code) {
            if (isset(self::FUNCTION_WORDS[$code])) {
                $lists[$code] = array_flip(array_unique(explode(' ', self::FUNCTION_WORDS[$code])));
                foreach ($lists[$code] as $word => $_) {
                    $seen[$word] = ($seen[$word] ?? 0) + 1;
                }
            }
        }
        $words = preg_split('/[^\pL]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $votes = array_fill_keys(array_keys($lists), 0);
        foreach ($words as $word) {
            if (($seen[$word] ?? 0) !== 1) {
                continue;
            }
            foreach ($lists as $code => $list) {
                if (isset($list[$word])) {
                    $votes[$code]++;
                }
            }
        }
        if (count($votes) < 2) {
            return '';
        }
        arsort($votes);
        [$first, $second] = array_slice(array_values($votes), 0, 2);

        return $first >= 2 && $first >= 2 * $second ? (string)array_key_first($votes) : '';
    }

    /**
     * @param list<string> $candidates
     */
    private function byNgrams(string $text, array $candidates, int $minWords): string
    {
        if (count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []) < $minWords || count($candidates) < 2) {
            return '';
        }
        try {
            $scores = (new Language($candidates))->detect($text)->close();
        } catch (\Throwable) {
            return '';
        }
        $values = array_values($scores);
        if (count($values) < 2 || $values[0] <= 0 || ($values[0] - $values[1]) / $values[0] < self::NGRAM_MARGIN) {
            return '';
        }

        return strtolower((string)strtok((string)array_key_first($scores), '-'));
    }

    /**
     * A stringlist setting comes as array, a flat one as comma-separated string.
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }

        return is_string($value) && trim($value) !== '' ? explode(',', $value) : [];
    }
}
