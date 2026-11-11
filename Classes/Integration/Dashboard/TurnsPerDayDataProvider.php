<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Integration\Dashboard;

use TYPO3\CMS\Dashboard\WidgetApi;
use TYPO3\CMS\Dashboard\Widgets\ChartDataProviderInterface;
use WapplerSystems\Meilisearch\Service\Rag\Statistics\UsageStatistics;

/**
 * Bar chart: chat questions per day, answered vs. without an answer.
 */
final class TurnsPerDayDataProvider implements ChartDataProviderInterface
{
    use TranslatesWidgetLabels;

    public function __construct(
        private readonly UsageStatistics $statistics,
        private readonly int $days = 30,
    ) {}

    public function getChartData(): array
    {
        $labels = [];
        $answered = [];
        $unanswered = [];
        foreach ($this->statistics->perDay(null, $this->days) as $day) {
            $labels[] = date('d.m.', (int)strtotime($day['day']));
            $answered[] = $day['turns'] - $day['unanswered'];
            $unanswered[] = $day['unanswered'];
        }
        $colors = WidgetApi::getDefaultChartColors();

        return [
            'labels' => $labels,
            'datasets' => [
                ['label' => $this->label('widget.turnsPerDay.answered'), 'backgroundColor' => $colors[0], 'border' => 0, 'data' => $answered],
                ['label' => $this->label('widget.turnsPerDay.unanswered'), 'backgroundColor' => $colors[1], 'border' => 0, 'data' => $unanswered],
            ],
        ];
    }
}
