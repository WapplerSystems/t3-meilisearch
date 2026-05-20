<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Llm;

/**
 * Looks up the LLM provider implementation by name. Providers are
 * auto-collected by the container via the `ws_meilisearch.llm_provider` tag
 * (configured in Services.yaml). A third-party extension that ships its own
 * provider only needs to implement LlmProviderInterface and let
 * `_instanceof` autoconfigure pick it up.
 */
final class LlmProviderRegistry
{
    /** @var array<string,LlmProviderInterface> */
    private array $byName = [];

    /**
     * @param iterable<LlmProviderInterface> $providers
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->byName[$provider->name()] = $provider;
        }
    }

    public function get(string $name): ?LlmProviderInterface
    {
        return $this->byName[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_keys($this->byName));
    }
}
