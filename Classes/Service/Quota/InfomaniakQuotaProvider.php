<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Quota;

use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Infomaniak AI Tools quota check.
 *
 * Current state of the world: Infomaniak's product-scoped completion
 * key (the one stored in meilisearch.rag.apiKey for chat and in
 * meilisearch.embedder.apiKey for embeddings) is authorised ONLY
 * against the explicit endpoints `/1/ai/<productId>/openai/v1/chat/
 * completions` and `/1/ai/<productId>/openai/v1/embeddings`. Every
 * candidate usage endpoint we tried returns
 *   {"code":"method_not_found"}
 * with the completion key — Infomaniak doesn't currently expose
 * product-scoped usage via that scope.
 *
 * To make this provider work the operator needs a separate
 * Manager-scope Personal Access Token (created at
 * manager.infomaniak.com → API), set in
 * meilisearch.quota.infomaniak.apiToken. Until that's configured we
 * report the explanatory error rather than silently passing — the
 * operator sees what's missing instead of a false "0% used" badge.
 *
 * Matches the `infomaniak` slug used as both RAG provider and
 * embedder source.
 */
final class InfomaniakQuotaProvider implements QuotaProviderInterface
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    public function name(): string
    {
        return 'Infomaniak AI Tools';
    }

    public function supports(string $providerSlug): bool
    {
        return strtolower($providerSlug) === 'infomaniak';
    }

    public function checkQuota(Site $site): QuotaStatus
    {
        $settings = $site->getSettings();
        $productId = trim((string)$settings->get('meilisearch.infomaniak.productId', ''));
        $managerToken = trim((string)$settings->get('meilisearch.quota.infomaniak.apiToken', ''));
        if ($productId === '') {
            return QuotaStatus::error('infomaniak', 'meilisearch.infomaniak.productId missing');
        }
        if ($managerToken === '') {
            return QuotaStatus::error(
                'infomaniak',
                'meilisearch.quota.infomaniak.apiToken not set — needs a Manager-scope Personal Access Token (manager.infomaniak.com → API). The AI completion key is only authorised against /chat/completions + /embeddings.',
            );
        }

        // Manager-scope endpoint for AI product usage. The path shape
        // here is best-effort based on Infomaniak's public docs and
        // may need updating when they publish a stable contract.
        $url = 'https://api.infomaniak.com/1/ai/' . rawurlencode($productId) . '/usage';
        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'timeout' => 15,
                'headers' => ['Authorization' => 'Bearer ' . $managerToken],
            ]);
        } catch (\Throwable $e) {
            return QuotaStatus::error('infomaniak', 'HTTP error: ' . $e->getMessage());
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return QuotaStatus::error('infomaniak', sprintf('HTTP %d from %s — check Manager token scope', $status, $url));
        }
        $payload = json_decode((string)$response->getBody(), true);
        if (!is_array($payload) || !isset($payload['data'])) {
            return QuotaStatus::error('infomaniak', 'Response missing "data" envelope');
        }
        $data = $payload['data'];

        // Infomaniak's payload key naming has varied between API
        // revisions; try the common shapes and report which keys
        // were present if none matched (helps operators discover the
        // current contract).
        $used = (int)($data['usage'] ?? $data['used'] ?? $data['tokens_used'] ?? 0);
        $limit = (int)($data['quota'] ?? $data['limit'] ?? $data['tokens_quota'] ?? 0);
        if ($limit === 0) {
            return QuotaStatus::error('infomaniak', 'Response did not include a recognisable quota field (got: ' . implode(',', array_keys($data)) . ')');
        }
        return QuotaStatus::ok('infomaniak', $used, $limit, 'tokens', 'current period');
    }
}
