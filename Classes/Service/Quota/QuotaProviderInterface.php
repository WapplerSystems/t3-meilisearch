<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Quota;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Contract for "is this commercial AI provider running out of quota".
 * Implementations match against the provider slug the site has set
 * (meilisearch.rag.provider, meilisearch.embedder.source, …) and
 * return the current usage / hard cap.
 *
 * Tagged_iterator wired in Services.yaml via
 * `ws_meilisearch.quota_provider`. Add a new provider by implementing
 * the interface; the dispatcher (QuotaCheckRunner) picks it up
 * automatically — no command-side wiring.
 */
interface QuotaProviderInterface
{
    /** Human-readable provider name for log / email rendering. */
    public function name(): string;

    /**
     * True when this provider can answer for the given slug. Slugs
     * come from site settings (e.g. 'openAi', 'anthropic',
     * 'infomaniak'). One provider may match more than one slug if it
     * underlies multiple meilisearch.* configurations.
     */
    public function supports(string $providerSlug): bool;

    /**
     * Read live usage for one site. Use {@see QuotaStatus::error()}
     * when the API call fails — the runner distinguishes "API
     * unreachable / mis-keyed" from "actually approaching the cap".
     */
    public function checkQuota(Site $site): QuotaStatus;
}
