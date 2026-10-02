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
     */
    public function toText(array $entries, array $labels = []): string
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

            $lines[] = sprintf(
                '#%d  %s  [%s]',
                $index + 1,
                date('Y-m-d H:i', $entry->crdate),
                $entry->status,
            );

            $lines[] = $this->formatTextBlock($labels['question'], $entry->question);
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
