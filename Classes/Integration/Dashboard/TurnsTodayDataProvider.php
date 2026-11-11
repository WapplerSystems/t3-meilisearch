<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Integration\Dashboard;

use TYPO3\CMS\Dashboard\Widgets\NumberWithIconDataProviderInterface;
use WapplerSystems\Meilisearch\Service\Rag\Statistics\UsageStatistics;

/**
 * Number widget: chat questions from visitors since midnight.
 */
final class TurnsTodayDataProvider implements NumberWithIconDataProviderInterface
{
    public function __construct(
        private readonly UsageStatistics $statistics,
    ) {}

    public function getNumber(): int
    {
        return $this->statistics->summary(null, (int)strtotime('today'))['turns'];
    }
}
