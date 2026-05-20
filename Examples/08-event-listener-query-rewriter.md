# 08 — Query rewriting before retrieval

Verbose user questions ("What is a passport?") often miss the keyword
retriever because "what", "is", "a" don't carry signal — Meilisearch
requires every word to match. A 5-line listener strips question words
before the search runs.

## Pattern

`BeforeRagQueryEvent` fires at the start of `RagService::ask`,
before retrieval. Listeners can mutate `$event->question` and
`$event->options`. The LLM still receives the *original* user
question — only the retrieval query is rewritten.

## Listener

```php
<?php
declare(strict_types=1);

namespace MyVendor\MyExt\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use WapplerSystems\Meilisearch\Event\BeforeRagQueryEvent;

#[AsEventListener]
final class StripQuestionWordsListener
{
    private const STOPWORDS_DE = [
        'wer', 'was', 'wie', 'wo', 'wann', 'warum', 'wieso', 'welche', 'welcher', 'welches',
        'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einer', 'einem', 'einen',
        'ist', 'sind', 'war', 'waren', 'kann', 'könnte', 'muss', 'soll', 'darf',
        'und', 'oder', 'aber', 'für', 'mit', 'ohne', 'auf', 'in', 'an', 'zu',
    ];
    private const STOPWORDS_EN = [
        'what', 'who', 'how', 'where', 'when', 'why', 'which',
        'is', 'are', 'was', 'were', 'can', 'could', 'should', 'must',
        'the', 'a', 'an', 'and', 'or', 'but', 'for', 'with', 'without', 'on', 'in', 'to',
    ];

    public function __invoke(BeforeRagQueryEvent $event): void
    {
        // Lower-case, drop punctuation, split.
        $words = preg_split(
            '/[\s\p{P}]+/u',
            mb_strtolower($event->question),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];

        $stops = array_flip(array_merge(self::STOPWORDS_DE, self::STOPWORDS_EN));
        $keep = array_filter($words, static fn ($w) => !isset($stops[$w]) && mb_strlen($w) > 2);

        // Only rewrite if we kept *something* — otherwise the original
        // (terse) question is the best signal we have.
        if ($keep !== []) {
            $event->question = implode(' ', $keep);
        }
    }
}
```

## Verify

Without the listener:

```
Q: "What is a passport?"
SearchService.search('What is a passport?') → 0 hits
→ RagAnswer::noContext (the LLM is never called)
```

With the listener:

```
Q: "What is a passport?"
BeforeRagQueryEvent → question rewritten to "passport"
SearchService.search('passport') → 4 hits
LLM gets the original "What is a passport?" in the user message,
  but with the right context attached.
```

## Trade-offs

- Stopword lists are language-specific and fragile. A library like
  `nikic/php-text-analysis` does this better, but the inline list
  works well enough for German + English sites.
- For multilingual sites, branch on the request's `Accept-Language`
  or the resolved site language.
- The listener does *not* alter the LLM's user message — only the
  retrieval query. So the LLM still sees the natural question and
  answers it naturally.

## When to use this

Always, if you're using keyword-only retrieval. With hybrid (and an
embedder configured) the semantic vector already understands "what
is a passport" without rewriting — at that point the listener is
unnecessary overhead.
