<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Quota;

use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Infomaniak AI Tools quota check — limited by Infomaniak's API.
 *
 * Verified 2026-06-07 with a Manager-scope Personal Access Token
 * (manager.infomaniak.com → API): Infomaniak exposes NO usage /
 * quota endpoint for AI products via the Manager API. Probed every
 * candidate path (`/1/ai/<id>/usage`, `/quota`, `/spending`,
 * `/billing`, `/stats`, `/dashboard`, `/details`, `/consumption`,
 * `/openai/v1/usage`, plus v2/v3 namespaces, plus query expansions)
 * — all 404. `/1/products` accepts a `service_name` filter but its
 * whitelist explicitly excludes AI. The completion key is restricted
 * to `/chat/completions` + `/embeddings`; the Manager key only
 * reaches `/1/ai` which returns just `{product_name, product_id,
 * account_name, status}` — no numbers.
 *
 * Until Infomaniak exposes a quota endpoint this provider does what
 * it can: it pings `/1/ai` to confirm the Manager token is valid
 * and the product is reachable, then reports an honest "no usage
 * data available via API" with a link to the Manager UI where the
 * operator can read the gauge by hand.
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
            // No token → can't even confirm reachability. Point operator
            // at the Manager UI which IS the canonical source.
            return QuotaStatus::error(
                'infomaniak',
                'Infomaniak does not expose a usage API for AI Tools. Check usage manually at https://manager.infomaniak.com/v3/ai/products/' . $productId . '/usage. (Set meilisearch.quota.infomaniak.apiToken to at least verify the product is reachable.)',
            );
        }

        // Confirm token validity + product reachability via /1/ai which
        // is the only AI-namespace endpoint that returns a 200 with the
        // Manager token. Returns {data:[{product_id, status}]} per AI
        // product the account owns.
        $url = 'https://api.infomaniak.com/1/ai';
        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'timeout' => 15,
                'headers' => ['Authorization' => 'Bearer ' . $managerToken],
            ]);
        } catch (\Throwable $e) {
            return QuotaStatus::error('infomaniak', 'HTTP error reaching ' . $url . ': ' . $e->getMessage());
        }
        $status = $response->getStatusCode();
        if ($status === 401 || $status === 403) {
            return QuotaStatus::error('infomaniak', sprintf('Manager token rejected (HTTP %d) — needs scope that includes /1/ai access', $status));
        }
        if ($status < 200 || $status >= 300) {
            return QuotaStatus::error('infomaniak', sprintf('HTTP %d from %s', $status, $url));
        }
        $payload = json_decode((string)$response->getBody(), true);
        $products = $payload['data'] ?? [];
        if (!is_array($products)) {
            return QuotaStatus::error('infomaniak', 'Unexpected /1/ai response shape');
        }
        $productState = null;
        foreach ($products as $product) {
            if ((int)($product['product_id'] ?? 0) === (int)$productId) {
                $productState = (string)($product['status'] ?? '');
                break;
            }
        }
        if ($productState === null) {
            return QuotaStatus::error('infomaniak', sprintf('Product %s not visible to this Manager token — check token scope', $productId));
        }

        // Reachable but no numbers available. Honest "data not available"
        // status so the CLI / BE doesn't pretend the quota is fine.
        return QuotaStatus::error(
            'infomaniak',
            sprintf(
                'Product %s reachable (status=%s). Infomaniak exposes no usage API for AI Tools — read the gauge at https://manager.infomaniak.com/v3/ai/products/%s/usage',
                $productId,
                $productState,
                $productId,
            ),
        );
    }
}
