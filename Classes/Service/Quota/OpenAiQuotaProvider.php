<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Quota;

use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * OpenAI usage check via the organisation usage report. Requires an
 * *admin* API key (sk-admin-...), NOT the completion key used by
 * RagService. Reads meilisearch.quota.openai.adminKey from site
 * settings so the regular completion key isn't shared with this
 * read-only metrics path.
 *
 * OpenAI's `/v1/organization/usage/completions` endpoint returns
 * token counts per day across the org's workspaces. Since OpenAI
 * doesn't expose a single "X% of monthly quota" number (their hard
 * cap is the operator-set monthly budget), we sum the current
 * month's tokens and compare against the operator-configured cap
 * in meilisearch.quota.openai.monthlyCap.
 */
final class OpenAiQuotaProvider implements QuotaProviderInterface
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    public function name(): string
    {
        return 'OpenAI';
    }

    public function supports(string $providerSlug): bool
    {
        return strtolower($providerSlug) === 'openai';
    }

    public function checkQuota(Site $site): QuotaStatus
    {
        $settings = $site->getSettings();
        $adminKey = trim((string)$settings->get('meilisearch.quota.openai.adminKey', ''));
        $cap = (int)$settings->get('meilisearch.quota.openai.monthlyCap', 0);
        if ($adminKey === '') {
            return QuotaStatus::error('openai', 'meilisearch.quota.openai.adminKey missing — needs sk-admin-* key');
        }
        if ($cap <= 0) {
            return QuotaStatus::error('openai', 'meilisearch.quota.openai.monthlyCap not set (number of tokens for the alert threshold)');
        }

        // Month-to-date in UTC; matches OpenAI's billing cycle.
        $start = (int)strtotime(gmdate('Y-m-01 00:00:00') . ' UTC');
        $url = sprintf(
            'https://api.openai.com/v1/organization/usage/completions?start_time=%d&bucket_width=1d',
            $start,
        );
        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'timeout' => 30,
                'headers' => ['Authorization' => 'Bearer ' . $adminKey],
            ]);
        } catch (\Throwable $e) {
            return QuotaStatus::error('openai', 'HTTP error: ' . $e->getMessage());
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return QuotaStatus::error('openai', sprintf('HTTP %d (admin key needed, not completion key)', $status));
        }
        $payload = json_decode((string)$response->getBody(), true);
        if (!is_array($payload) || !is_array($payload['data'] ?? null)) {
            return QuotaStatus::error('openai', 'Response missing data buckets');
        }

        $totalTokens = 0;
        foreach ($payload['data'] as $bucket) {
            foreach ((array)($bucket['results'] ?? []) as $entry) {
                $totalTokens += (int)($entry['input_tokens'] ?? 0)
                    + (int)($entry['output_tokens'] ?? 0)
                    + (int)($entry['input_cached_tokens'] ?? 0);
            }
        }
        return QuotaStatus::ok('openai', $totalTokens, $cap, 'tokens', 'month-to-date');
    }
}
