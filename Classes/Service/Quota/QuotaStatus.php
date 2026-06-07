<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Quota;

/**
 * One commercial-provider's current usage snapshot. Built by each
 * QuotaProviderInterface implementation; consumed by QuotaCheckRunner
 * (warning decision + email template).
 *
 * `used` and `limit` units are provider-specific; `unit` carries the
 * label so the warning email reads naturally ("4.2M of 5.0M tokens"
 * vs "9.8M of 10M characters").
 *
 * `error` is set when the API call failed (auth, rate limit, missing
 * config) — `usedPercent` is 0.0 in that case and the runner emits
 * an error notice instead of a quota warning. We don't want a 401
 * to silently masquerade as "0% used".
 */
final readonly class QuotaStatus
{
    public function __construct(
        public string $provider,
        public int $used,
        public int $limit,
        public float $usedPercent,
        public string $unit,
        public string $period,
        public ?string $error = null,
    ) {}

    public static function ok(string $provider, int $used, int $limit, string $unit, string $period): self
    {
        $pct = $limit > 0 ? round(($used / $limit) * 100.0, 2) : 0.0;
        return new self($provider, $used, $limit, $pct, $unit, $period);
    }

    public static function error(string $provider, string $message): self
    {
        return new self($provider, 0, 0, 0.0, '', '', $message);
    }

    public function isError(): bool
    {
        return $this->error !== null;
    }
}
