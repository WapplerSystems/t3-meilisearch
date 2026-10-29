<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag\Protocol;

/**
 * Plain-text transcript of a chat conversation, intended as an e-mail
 * attachment for a support team.
 */
final class ChatProtocolTranscript
{
    private const DEFAULT_LABELS = [
        'title' => 'Chat protocol',
        'conversation' => 'Conversation',
        'question' => 'Question',
        'answer' => 'Answer',
        'status' => 'Status',
        'sources' => 'Sources',
        'cited' => 'cited',
        'escalated' => 'contact offered',
        'noAnswer' => '(no answer)',
    ];

    /**
     * The transcript labels in a given language, from locallang_be.xlf —
     * for the backend download in the editor's language and for a mail
     * attachment in the language of whoever reads the mail.
     *
     * @return array<string,string>
     */
    public static function labels(\TYPO3\CMS\Core\Localization\LanguageService $languageService): array
    {
        $labels = [];
        foreach (array_keys(self::DEFAULT_LABELS) as $key) {
            $text = $languageService->sL('LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:transcript.' . $key);
            if ($text !== '') {
                $labels[$key] = $text;
            }
        }

        return $labels;
    }

    /**
     * @param list<ProtocolEntry> $entries
     * @param array<string,string> $labels
     * @param bool $citedSourcesOnly For a reader outside the team (the support
     *        mail, which the customer sees too): only the sources the answer
     *        cites, with public links, numbered like in the chat
     *        ({@see publicCitations()}). The default lists every context hit,
     *        cited or not, with raw ids — what quality control needs.
     */
    public function toText(array $entries, array $labels = [], bool $citedSourcesOnly = false): string
    {
        if ($entries === []) {
            return '';
        }

        $labels = array_replace(self::DEFAULT_LABELS, $labels);

        $first = $entries[0];
        $last = $entries[array_key_last($entries)];

        $lines = [
            $labels['title'],
            $labels['conversation'] . ': ' . $first->conversationId,
            date('Y-m-d H:i', $first->crdate) . ' – ' . date('Y-m-d H:i', $last->crdate),
            '',
        ];

        foreach ($entries as $index => $entry) {
            if ($index > 0) {
                $lines[] = '';
            }

            // The status (ok, no_answer …) is the team's classification, not
            // something to show a customer.
            $lines[] = $citedSourcesOnly
                ? sprintf('#%d  %s', $index + 1, date('Y-m-d H:i', $entry->crdate))
                : sprintf('#%d  %s  [%s]', $index + 1, date('Y-m-d H:i', $entry->crdate), $entry->status);

            $lines[] = $this->formatTextBlock($labels['question'], $entry->question);
            if ($citedSourcesOnly) {
                [$answer, $cited] = self::publicCitations($entry);
                $lines[] = $this->formatTextBlock($labels['answer'], $answer !== '' ? $answer : $labels['noAnswer']);
                if ($cited !== []) {
                    $lines[] = $labels['sources'] . ':';
                    foreach ($cited as $ref) {
                        $lines[] = '  [' . $ref['number'] . '] ' . $ref['title'] . ' ' . $ref['uri'];
                    }
                }
                if ($entry->escalated) {
                    $lines[] = '(' . $labels['escalated'] . ')';
                }
                continue;
            }

            $lines[] = $this->formatTextBlock(
                $labels['answer'],
                $entry->answer !== '' ? $entry->answer : $labels['noAnswer'],
            );

            if ($entry->sources !== []) {
                $lines[] = $labels['sources'] . ':';
                foreach ($entry->sources as $source) {
                    $id = (string)($source['id'] ?? '');
                    $title = (string)($source['title'] ?? '');
                    $uri = (string)($source['uri'] ?? '');

                    $sourceTitle = $title !== '' ? $title : $id;
                    $marker = $entry->wasCited($id) ? '  *' : '  -';
                    $line = $marker . ' ' . $sourceTitle;

                    if ($uri !== '') {
                        $line .= ' ' . $uri;
                    }

                    if ($entry->wasCited($id)) {
                        $line .= ' (' . $labels['cited'] . ')';
                    }

                    $lines[] = $line;
                }
            }

            if ($entry->escalated) {
                $lines[] = '(' . $labels['escalated'] . ')';
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The answer as a customer reads it, with the sources it cites.
     *
     * Citation markers become [1], [2] … in order of first appearance, one
     * number per link. Markers of internal entries (fact sheet, program
     * messages, help topics — type knowledge_resource, never shown as a
     * source) and of sources without a full public link are dropped, and so
     * are those sources. An answer that found nothing cites nothing and gets
     * no list. Mirrors CitationRenderer for plain text.
     *
     * @return array{0:string,1:list<array{number:int,title:string,uri:string}>}
     */
    private static function publicCitations(ProtocolEntry $entry): array
    {
        $byId = [];
        foreach ($entry->sources as $source) {
            $id = (string)($source['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $source;
            }
        }
        $refs = [];
        $answer = (string)preg_replace_callback(
            '/(\s*)\[([^\[\]]+)\]/u',
            static function (array $block) use ($byId, &$refs): string {
                preg_match_all('/[A-Za-z0-9_:.\-]+/', $block[2], $tokens);
                $numbers = [];
                $known = false;
                foreach ($tokens[0] as $token) {
                    if (!isset($byId[$token])) {
                        continue;
                    }
                    $known = true;
                    $source = $byId[$token];
                    $uri = (string)($source['uri'] ?? '');
                    if (($source['type'] ?? '') === 'knowledge_resource' || preg_match('#^https?://#i', $uri) !== 1) {
                        continue;
                    }
                    $refs[$uri] ??= [
                        'number' => count($refs) + 1,
                        'title' => (string)($source['title'] ?? '') !== '' ? (string)$source['title'] : $token,
                        'uri' => $uri,
                    ];
                    $numbers[$refs[$uri]['number']] = true;
                }
                if (!$known) {
                    // Not a citation, prose in brackets ("[Enter]").
                    return $block[0];
                }
                if ($numbers === []) {
                    return '';
                }
                ksort($numbers);

                return $block[1] . implode('', array_map(static fn (int $n): string => '[' . $n . ']', array_keys($numbers)));
            },
            $entry->answer,
        );

        return [$answer, array_values($refs)];
    }

    private function formatTextBlock(string $label, string $text): string
    {
        $lines = explode("\n", $text);
        $firstLine = array_shift($lines) ?? '';

        $result = $label . ': ' . $firstLine;
        foreach ($lines as $line) {
            // Indent continuation lines so they read as part of the block;
            // blank lines stay blank instead of carrying trailing spaces.
            $result .= "\n" . (trim($line) === '' ? '' : '  ' . $line);
        }

        return $result;
    }
}
