<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Event;

/**
 * Dispatched just before the LLM provider is invoked. Listeners may
 * short-circuit by setting `$response`, in which case the provider call is
 * skipped — useful for prompt caching or off-line replay during tests.
 *
 * @phpstan-type Message array{role:string,content:string}
 */
final class BeforeLlmCallEvent
{
    /**
     * @param list<Message>      $messages
     * @param array<string,mixed> $options
     */
    public function __construct(
        public array $messages,
        public array $options,
        public ?string $response = null,
    ) {}
}
