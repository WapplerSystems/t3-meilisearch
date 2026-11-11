<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Integration\Dashboard;

use TYPO3\CMS\Dashboard\WidgetApi;
use TYPO3\CMS\Dashboard\Widgets\ChartDataProviderInterface;
use WapplerSystems\Meilisearch\Service\Rag\Statistics\UsageStatistics;

/**
 * Doughnut chart: helpful / not helpful / not rated over the last days.
 */
final class RatingDataProvider implements ChartDataProviderInterface
{
    use TranslatesWidgetLabels;

    public function __construct(
        private readonly UsageStatistics $statistics,
        private readonly int $days = 30,
    ) {}

    public function getChartData(): array
    {
        $summary = $this->statistics->summary(null, time() - $this->days * 86400);
        $colors = WidgetApi::getDefaultChartColors();

        return [
            'labels' => [
                $this->label('widget.rating.up'),
                $this->label('widget.rating.down'),
                $this->label('widget.rating.none'),
            ],
            'datasets' => [[
                'backgroundColor' => [$colors[0], $colors[1], $colors[4] ?? $colors[2]],
                'data' => [
                    $summary['ratedUp'],
                    $summary['ratedDown'],
                    max(0, $summary['turns'] - $summary['ratedUp'] - $summary['ratedDown']),
                ],
            ]],
        ];
    }
}
