<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag\Statistics;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\Rag\Protocol\ChatProtocolRecorder;

/**
 * Usage figures of the chat for monitoring (zabbix_client operation) and the
 * backend dashboard widgets — one place, so both show the same numbers.
 *
 * Source is the chat protocol (one row per turn), so the figures need
 * meilisearch.rag.protocol enabled. Only the web channel counts by default:
 * MCP turns are the site owner's own test questions. Refused questions and
 * withdrawn answers come from UsageCounter.
 *
 * A null site means all sites together. Public, so other packages can read
 * the same figures.
 */
#[Autoconfigure(public: true)]
final class UsageStatistics
{
    private const TABLE = 'tx_wsmeilisearch_rag_protocol';

    /** Statuses of a turn that did not get an answer from the sources. */
    public const UNANSWERED = ['no_context', 'failed', 'withdrawn'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly UsageCounter $usageCounter,
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * @return array{turns:int,conversations:int,status:array<string,int>,escalated:int,ratedUp:int,ratedDown:int,lastTurn:int}
     */
    public function summary(?string $siteIdentifier, int $since, string $channel = ChatProtocolRecorder::CHANNEL_WEB): array
    {
        $summary = ['turns' => 0, 'conversations' => 0, 'status' => [], 'escalated' => 0, 'ratedUp' => 0, 'ratedDown' => 0, 'lastTurn' => 0];
        foreach (['ok', 'no_context', 'failed', 'clarify', 'aborted', 'off_topic', 'withdrawn'] as $status) {
            $summary['status'][$status] = 0;
        }
        $conversations = [];
        $qb = $this->select($siteIdentifier, $since, $channel, ['conversation_id', 'status', 'escalated', 'rating', 'crdate']);
        $result = $qb->executeQuery();
        while ($row = $result->fetchAssociative()) {
            $summary['turns']++;
            $conversations[(string)$row['conversation_id']] = true;
            $status = (string)$row['status'];
            $summary['status'][$status] = ($summary['status'][$status] ?? 0) + 1;
            $summary['escalated'] += (int)$row['escalated'];
            $summary['ratedUp'] += (int)$row['rating'] > 0 ? 1 : 0;
            $summary['ratedDown'] += (int)$row['rating'] < 0 ? 1 : 0;
            $summary['lastTurn'] = max($summary['lastTurn'], (int)$row['crdate']);
        }
        $summary['conversations'] = count($conversations);

        return $summary;
    }

    /**
     * Newest turn of the channel at all, independent of a window — "how long
     * has nobody asked?" must not end at the window's edge.
     */
    public function lastTurn(?string $siteIdentifier, string $channel = ChatProtocolRecorder::CHANNEL_WEB): int
    {
        $qb = $this->select($siteIdentifier, 0, $channel, []);
        $qb->selectLiteral('MAX(crdate)');

        return (int)$qb->executeQuery()->fetchOne();
    }

    /**
     * Turns and unanswered turns per calendar day (server time), oldest first,
     * days without traffic included.
     *
     * @return list<array{day:string,turns:int,unanswered:int}>
     */
    public function perDay(?string $siteIdentifier, int $days, string $channel = ChatProtocolRecorder::CHANNEL_WEB): array
    {
        $buckets = [];
        $start = strtotime('today') - ($days - 1) * 86400;
        for ($i = 0; $i < $days; $i++) {
            $day = date('Y-m-d', $start + $i * 86400);
            $buckets[$day] = ['day' => $day, 'turns' => 0, 'unanswered' => 0];
        }
        $result = $this->select($siteIdentifier, $start, $channel, ['crdate', 'status'])->executeQuery();
        while ($row = $result->fetchAssociative()) {
            $day = date('Y-m-d', (int)$row['crdate']);
            if (!isset($buckets[$day])) {
                continue;
            }
            $buckets[$day]['turns']++;
            if (in_array((string)$row['status'], self::UNANSWERED, true)) {
                $buckets[$day]['unanswered']++;
            }
        }

        return array_values($buckets);
    }

    /**
     * Most frequent questions, compared case-insensitively.
     *
     * @return list<array{question:string,count:int}>
     */
    public function topQuestions(?string $siteIdentifier, int $since, int $limit, string $channel = ChatProtocolRecorder::CHANNEL_WEB): array
    {
        $counts = [];
        $labels = [];
        $result = $this->select($siteIdentifier, $since, $channel, ['question'])->executeQuery();
        while ($row = $result->fetchAssociative()) {
            $question = trim(preg_replace('/\s+/u', ' ', (string)$row['question']) ?? '');
            if ($question === '') {
                continue;
            }
            $key = mb_strtolower($question);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $labels[$key] ??= $question;
        }
        arsort($counts);
        $top = [];
        foreach (array_slice($counts, 0, $limit, true) as $key => $count) {
            $top[] = ['question' => $labels[$key], 'count' => $count];
        }

        return $top;
    }

    /**
     * Latest turns that went wrong for the visitor: no answer from the
     * sources, a failure, or a thumbs down.
     *
     * @return list<array{question:string,crdate:int,status:string,rating:int}>
     */
    public function latestProblems(?string $siteIdentifier, int $since, int $limit, string $channel = ChatProtocolRecorder::CHANNEL_WEB): array
    {
        $qb = $this->select($siteIdentifier, $since, $channel, ['question', 'crdate', 'status', 'rating']);
        $qb->andWhere($qb->expr()->or(
            $qb->expr()->in('status', $qb->createNamedParameter(self::UNANSWERED, Connection::PARAM_STR_ARRAY)),
            $qb->expr()->lt('rating', $qb->createNamedParameter(0, Connection::PARAM_INT)),
        ))->orderBy('crdate', 'DESC')->setMaxResults($limit);

        return array_map(static fn(array $row): array => [
            'question' => (string)$row['question'],
            'crdate' => (int)$row['crdate'],
            'status' => (string)$row['status'],
            'rating' => (int)$row['rating'],
        ], $qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * Refused questions (by reason), accepted questions and withdrawn
     * answers since $since, summed over the given site or all sites.
     *
     * @return array<string,int>
     */
    public function counters(?string $siteIdentifier, int $since): array
    {
        $identifiers = $siteIdentifier !== null
            ? [$siteIdentifier]
            : array_keys($this->siteFinder->getAllSites());
        $sum = [];
        foreach ($identifiers as $identifier) {
            foreach ($this->usageCounter->sumSince($identifier, $since) as $name => $value) {
                $sum[$name] = ($sum[$name] ?? 0) + $value;
            }
        }

        return $sum;
    }

    /**
     * @param list<string> $fields
     */
    private function select(?string $siteIdentifier, int $since, string $channel, array $fields): QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->select(...$fields)
            ->from(self::TABLE)
            ->where($qb->expr()->gte('crdate', $qb->createNamedParameter($since, Connection::PARAM_INT)));
        if ($channel !== '') {
            $qb->andWhere($qb->expr()->eq('channel', $qb->createNamedParameter($channel)));
        }
        if ($siteIdentifier !== null) {
            $qb->andWhere($qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)));
        }

        return $qb;
    }
}
