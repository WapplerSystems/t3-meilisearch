<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Configuration\SearchConfigurationProvider;

/**
 * Translates the per-record TCA enum (tx_wsmeilisearch_boost: 0..4) and the
 * per-type Site Settings multiplier (meilisearch.boosts.types.<type>) into a
 * single float that the indexer writes to the document's `boost` field.
 *
 * Composition is multiplicative — a "high" record (1.5) of a type with default
 * multiplier 1.5 ends up at 2.25, doubling its rank weight versus a normal
 * record of an unweighted type. This keeps both knobs independently meaningful
 * for the integrator (per-type policy) and editor (per-record exception).
 *
 * The TCA enum is mapped:
 *   0 = very low   → 0.50
 *   1 = low        → 0.75
 *   2 = normal     → 1.00  (default, also returned when value is null/missing)
 *   3 = high       → 1.50
 *   4 = very high  → 2.00
 *
 * Numbers chosen so that one step is a ~50% rank swing and the full range
 * spans 4x — enough to clearly reorder hits without making low-boosted
 * records permanently invisible.
 */
final class BoostCalculator
{
    public const ENUM_VERY_LOW  = 0;
    public const ENUM_LOW       = 1;
    public const ENUM_NORMAL    = 2;
    public const ENUM_HIGH      = 3;
    public const ENUM_VERY_HIGH = 4;

    /** @var array<int, float> */
    private const ENUM_TO_MULTIPLIER = [
        self::ENUM_VERY_LOW  => 0.50,
        self::ENUM_LOW       => 0.75,
        self::ENUM_NORMAL    => 1.00,
        self::ENUM_HIGH      => 1.50,
        self::ENUM_VERY_HIGH => 2.00,
    ];

    public function __construct(
        private readonly SearchConfigurationProvider $configProvider,
    ) {}

    /**
     * Editor TCA enum → multiplier. Unknown / null values fall back to 1.0
     * (normal) so old records without the column don't lose ranking weight.
     */
    public function recordMultiplier(int|null $enumValue): float
    {
        if ($enumValue === null) {
            return 1.0;
        }
        return self::ENUM_TO_MULTIPLIER[$enumValue] ?? 1.0;
    }

    /**
     * Composite multiplier (type-level × record-level) for one document.
     * `$recordEnum` is the raw tx_wsmeilisearch_boost integer or null when
     * the column doesn't exist / is unset.
     */
    public function compositeFor(Site $site, string $type, int|null $recordEnum): float
    {
        $record = $this->configProvider->isRecordBoostEnabled($site)
            ? $this->recordMultiplier($recordEnum)
            : 1.0;
        return $this->configProvider->typeBoost($site, $type) * $record;
    }
}