# 07 — Cache identical RAG calls with `BeforeLlmCallEvent`

Every visitor who asks the same question pays for the same LLM call.
For FAQ-style sites that's wasteful. A 10-line listener short-circuits
identical requests with a cached answer, dropping the per-call cost
to ~zero.

## Pattern

`RagService` dispatches `BeforeLlmCallEvent` after building the
prompt and before calling the provider. If a listener sets
`$event->response`, the provider call is skipped and the cached value
is used verbatim.

## Listener

`Classes/EventListener/CachedAnswerListener.php`:

```php
<?php
declare(strict_types=1);

namespace MyVendor\MyExt\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use WapplerSystems\Meilisearch\Event\BeforeLlmCallEvent;

#[AsEventListener]
final class CachedAnswerListener
{
    public function __construct(
        // Configure as a SimpleFileBackend in ext_localconf.php (see below).
        private readonly FrontendInterface $cache,
    ) {}

    public function __invoke(BeforeLlmCallEvent $event): void
    {
        $key = $this->keyFor($event->messages);
        $cached = $this->cache->get($key);
        if (is_string($cached) && $cached !== '') {
            // Short-circuit: RagService skips the provider call entirely.
            $event->response = $cached;
            return;
        }
        // Stash the key on the event so an `AfterRagAnswerEvent` listener
        // can write the fresh answer back into the cache. The cache key
        // is deterministic, so a sibling listener can recompute it just
        // as easily.
    }

    /**
     * @param list<array{role:string,content:string}> $messages
     */
    private function keyFor(array $messages): string
    {
        return 'rag_' . sha1(json_encode($messages, JSON_THROW_ON_ERROR));
    }
}
```

Companion to populate the cache on cache miss:

```php
<?php
declare(strict_types=1);

namespace MyVendor\MyExt\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use WapplerSystems\Meilisearch\Event\AfterRagAnswerEvent;

#[AsEventListener]
final class FillAnswerCacheListener
{
    public function __construct(
        private readonly FrontendInterface $cache,
        // Re-derive the prompt the same way the upstream listener does.
        // (In a real impl, share the keyFor() helper.)
    ) {}

    public function __invoke(AfterRagAnswerEvent $event): void
    {
        if ($event->answer->status !== 'ok') {
            return;
        }
        // Persist for 1 hour. For a totally static FAQ corpus, bump much higher.
        $this->cache->set(
            'rag_' . sha1($event->question),
            $event->answer->answer,
            ['rag_answers'],
            3600,
        );
    }
}
```

(For a clean implementation, keep the cache key derivation in one
spot — pass it via a custom event property or store the key in a
small KV store keyed by the question text.)

## Cache registration

`ext_localconf.php`:

```php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['rag_answers'] ??= [
    'backend' => \TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend::class,
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'groups' => ['system'],
    'options' => ['defaultLifetime' => 3600],
];
```

## When to use this

- Public FAQ pages where the same 20 questions get 80% of the traffic.
- Multi-turn off — caching multi-turn responses requires keying on
  the *entire* history, which means the cache hit rate collapses.
  For multi-turn setups, prefer to use Anthropic prompt caching at
  the provider layer or accept the recurring cost.

## When NOT to

- Personalized answers (e.g. retrieval gates content by frontend
  user group). Cache-key the user identifier into the hash, or skip
  caching for logged-in visitors entirely.
- Compliance contexts where every answer must be regenerated for
  audit reasons.
