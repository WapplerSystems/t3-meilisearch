<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Integration\Dashboard;

use TYPO3\CMS\Dashboard\Widgets\ListDataProviderInterface;
use WapplerSystems\Meilisearch\Service\Rag\Statistics\UsageStatistics;

/**
 * List: latest turns without an answer, failed or rated not helpful — the
 * ones worth a look in the chat protocol.
 */
final class ProblemsDataProvider implements ListDataProviderInterface
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
        foreach ($this->statistics->latestProblems(null, time() - $this->days * 86400, $this->limit) as $row) {
            $kind = $row['rating'] < 0 ? 'ratedDown' : $row['status'];
            $items[] = date('d.m. H:i', $row['crdate']) . ' · ' . $this->label('widget.problems.' . $kind)
                . ' · ' . $this->shorten($row['question']);
        }

        return $items ?: [$this->label('widget.empty')];
    }
}
