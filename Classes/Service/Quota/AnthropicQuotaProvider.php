<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Quota;

use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Anthropic usage check via the organisation usage report. Like the
 * OpenAI sibling, this needs an *admin* API key (separate from the
 * completion key) configured in
 * meilisearch.quota.anthropic.adminKey. The cap comes from
 * meilisearch.quota.anthropic.monthlyCap (Anthropic doesn't return a
 * single "% of quota" value either — operators set the alert
 * threshold themselves).
 *
 * Endpoint: /v1/organizations/usage_report with anthropic-version
 * header. Returns daily token counts; we sum month-to-date and
 * compare to the cap.
 */
final class AnthropicQuotaProvider implements QuotaProviderInterface
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    public function name(): string
    {
        return 'Anthropic';
    }

    public function supports(string $providerSlug): bool
    {
        return strtolower($providerSlug) === 'anthropic';
    }

    public function checkQuota(Site $site): QuotaStatus
    {
        $settings = $site->getSettings();
        $adminKey = trim((string)$settings->get('meilisearch.quota.anthropic.adminKey', ''));
        $cap = (int)$settings->get('meilisearch.quota.anthropic.monthlyCap', 0);
        if ($adminKey === '') {
            return QuotaStatus::error('anthropic', 'meilisearch.quota.anthropic.adminKey missing — needs an admin API key, not the completion key');
        }
        if ($cap <= 0) {
            return QuotaStatus::error('anthropic', 'meilisearch.quota.anthropic.monthlyCap not set (tokens) — needed to compute %');
        }

        $startsAt = gmdate('Y-m-01\T00:00:00\Z');
        $endsAt = gmdate('Y-m-d\TH:i:s\Z');
        $url = sprintf(
            'https://api.anthropic.com/v1/organizations/usage_report/messages?starting_at=%s&ending_at=%s&bucket_width=1d',
            rawurlencode($startsAt),
            rawurlencode($endsAt),
        );
        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'timeout' => 30,
                'headers' => [
                    'x-api-key' => $adminKey,
                    'anthropic-version' => '2023-06-01',
                ],
            ]);
        } catch (\Throwable $e) {
            return QuotaStatus::error('anthropic', 'HTTP error: ' . $e->getMessage());
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return QuotaStatus::error('anthropic', sprintf('HTTP %d (admin key required, completion key denied)', $status));
        }
        $payload = json_decode((string)$response->getBody(), true);
        if (!is_array($payload) || !is_array($payload['data'] ?? null)) {
            return QuotaStatus::error('anthropic', 'Response missing data buckets');
        }

        $totalTokens = 0;
        foreach ($payload['data'] as $bucket) {
            foreach ((array)($bucket['results'] ?? []) as $entry) {
                $totalTokens += (int)($entry['uncached_input_tokens'] ?? 0)
                    + (int)($entry['cache_creation']['ephemeral_5m_input_tokens'] ?? 0)
                    + (int)($entry['cache_creation']['ephemeral_1h_input_tokens'] ?? 0)
                    + (int)($entry['cache_read_input_tokens'] ?? 0)
                    + (int)($entry['output_tokens'] ?? 0);
            }
        }
        return QuotaStatus::ok('anthropic', $totalTokens, $cap, 'tokens', 'month-to-date');
    }
}
