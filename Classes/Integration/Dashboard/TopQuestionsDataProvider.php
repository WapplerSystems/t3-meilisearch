<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Integration\Dashboard;

use TYPO3\CMS\Dashboard\Widgets\ListDataProviderInterface;
use WapplerSystems\Meilisearch\Service\Rag\Statistics\UsageStatistics;

/**
 * List: the most frequent chat questions of the last days ("3× question").
 */
final class TopQuestionsDataProvider implements ListDataProviderInterface
{
    use TranslatesWidgetLabels;

    public function __construct(
        private readonly UsageStatistics $statistics,
        private readonly int $days = 7,
        private readonly int $limit = 10,
    ) {}

    public function getItems(): array
    {
        $items = [];
        foreach ($this->statistics->topQuestions(null, time() - $this->days * 86400, $this->limit) as $row) {
            $items[] = $row['count'] . '× ' . $this->shorten($row['question']);
        }

        return $items ?: [$this->label('widget.empty')];
    }
}
