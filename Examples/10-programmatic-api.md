# 10 — Programmatic API usage

Call `SearchService` and `RagService` from your own code. Useful for
custom controllers, scheduler tasks that act on search results, or
backend modules that ask the index questions on behalf of an editor.

## Inject the services

Both are `public: true` in the extension's `Services.yaml`, so
autowiring works:

```php
<?php
declare(strict_types=1);

namespace MyVendor\MyExt\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\Rag\Conversation;
use WapplerSystems\Meilisearch\Service\Rag\RagService;
use WapplerSystems\Meilisearch\Service\Rag\Turn;
use WapplerSystems\Meilisearch\Service\SearchService;

#[AsController]
final class MyCustomController
{
    public function __construct(
        private readonly SearchService $searchService,
        private readonly RagService $ragService,
        private readonly SiteFinder $siteFinder,
        private readonly ModuleTemplateFactory $tplFactory,
    ) {}
    // ...
}
```

## Pattern A: keyword search

```php
$site = $this->siteFinder->getSiteByIdentifier('main');
$result = $this->searchService->search($site, 'saskatchewan', [
    'page' => 1,
    'perPage' => 20,
    'filters' => [
        'type' => 'file',
        'language' => 0,
    ],
    'facets' => ['type', 'language', 'mimeType'],
]);

foreach ($result->hits as $hit) {
    echo $hit['id'], ' — ', $hit['title'], PHP_EOL;
}
echo 'Total: ', $result->totalHits, PHP_EOL;
echo 'Type facet: ', json_encode($result->facets['type'] ?? []), PHP_EOL;
```

## Pattern B: hybrid search with override

```php
$result = $this->searchService->search($site, 'how do I reset my password', [
    'hybrid' => true,
    'semanticRatio' => 0.8,  // lean heavily on semantic for this query
    'perPage' => 5,
]);
```

`semanticRatio` overrides the site default per-call. Useful when you
know one specific query benefits from semantic matching more than
the default 0.5 mix.

## Pattern C: single-turn RAG

```php
$answer = $this->ragService->ask($site, 'How do I reset my password?');
if ($answer->status === 'ok') {
    echo $answer->answer, PHP_EOL;
    foreach ($answer->sources as $src) {
        $cited = in_array((string)$src['id'], $answer->citedIds, true) ? '✓' : ' ';
        echo "  $cited [{$src['id']}] {$src['title']}\n";
    }
} else {
    // 'disabled' | 'no_context' | 'failed'
    echo "Status: {$answer->status}", PHP_EOL;
}
```

## Pattern D: multi-turn RAG (managed externally)

If you're driving the conversation yourself — e.g. from a backend
helper widget that doesn't share a session with the FE plugin — pass
the Conversation explicitly each time:

```php
$conversation = Conversation::empty();

// Turn 1
$answer = $this->ragService->ask($site, 'What is X?', [
    'conversation' => $conversation,
]);
if ($answer->status === 'ok') {
    $conversation = $conversation->withTurn(
        new Turn('What is X?', $answer->answer, $answer->citedIds),
        maxTurns: 3,
    );
}

// Turn 2 — LLM sees Q1+A1 followed by Q2 with fresh context
$answer = $this->ragService->ask($site, 'Tell me more.', [
    'conversation' => $conversation,
]);
$conversation = $conversation->withTurn(/* ... */, 3);

// Serialize for storage (e.g. into your own DB):
$json = json_encode($conversation->toArray(), JSON_THROW_ON_ERROR);

// Hydrate back later:
$conversation = Conversation::fromArray(json_decode($json, true, 32, JSON_THROW_ON_ERROR));
```

## Pattern E: indexing from your own scheduler task

```php
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use WapplerSystems\Meilisearch\Service\IndexerService;

final class ReindexProductsTask extends AbstractTask
{
    public function execute(): bool
    {
        $indexer = GeneralUtility::makeInstance(IndexerService::class);
        $finder = GeneralUtility::makeInstance(SiteFinder::class);
        foreach ($finder->getAllSites() as $site) {
            if (!$indexer->ensureSchema($site, rebuild: false, skipEmbedder: true)) {
                continue;
            }
            // Only reindex the product rows we care about, e.g. ones with
            // a stale tstamp. Loop over them and call indexRecord directly.
            foreach ($this->staleProductUids() as $uid) {
                $indexer->indexRecord('tx_products_product', $uid, $site);
            }
        }
        return true;
    }
}
```

`indexRecord` is idempotent and uses the same schema providers as the
full reindex, so partial / incremental sync compose cleanly with the
nightly full reindex task that ships with the extension.

## When to drop down to the API

- You want to feed search results into another system (e.g. an
  internal Slack bot).
- You need a non-Fluid frontend (React SPA hitting an AJAX endpoint
  that calls `SearchService` under the hood).
- You're orchestrating multi-step workflows (e.g. "find docs ↔ ask
  LLM to summarize each ↔ aggregate") that don't fit the
  single-action Extbase controller shape.
