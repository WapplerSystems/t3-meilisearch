<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Command;

use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Configuration\SearchConfigurationProvider;
use WapplerSystems\Meilisearch\Domain\Repository\DictionaryRepository;
use WapplerSystems\Meilisearch\Service\QueryAlternative;
use WapplerSystems\Meilisearch\Service\QueryRecoveryService;

/**
 * Proposes dictionary entries instead of waiting for someone to think of
 * them. Writes rows to tx_wsmeilisearch_dictionary in state "candidate";
 * nothing reaches the search engine until an editor flips one to "active".
 *
 * Three independent miners, because each finds a different class of gap:
 *
 *  1. RECOVERY (default). Replays the zero-result queries from the search
 *     log through QueryRecoveryService and writes down what worked. This
 *     is the runtime rescue made permanent: as long as the entry is only
 *     a recovery, every visitor pays a second multi-search for it and the
 *     hits rank as if the words were unrelated. As a synonym, the engine
 *     resolves it inside the first query.
 *
 *  2. REFORMULATION. Real user wording: a query with zero hits followed
 *     shortly by a similar query WITH hits, on the same site and language.
 *     No session id is stored (see SearchAnalyticsLogger's privacy note),
 *     so the pairing is by time proximity plus a similarity test — good
 *     enough to propose, never good enough to apply on its own.
 *
 *  3. TRANSLATION. The site is its own bilingual glossary: a page or
 *     knowledge-base topic whose German title is one word and whose
 *     English overlay is another gives a term pair for free. That is where
 *     "curtain wall ↔ Vorhangfassade" comes from — a class of miss no
 *     typo tolerance can ever catch.
 */
#[AsCommand(
    name: 'ws_meilisearch:dictionary-mine',
    description: 'Propose synonyms and single-token words from the search log, user reformulations and translated titles.',
)]
final class DictionaryMineCommand extends Command
{
    /**
     * A reformulation this far apart is a new visitor, not the same person
     * trying again.
     */
    private const REFORMULATION_WINDOW = 180;

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly ConnectionPool $connectionPool,
        private readonly DictionaryRepository $dictionary,
        private readonly QueryRecoveryService $recovery,
        private readonly SearchConfigurationProvider $configProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'Limit to one site identifier. Default: every site with a Meilisearch URL.')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How far back to read the search log.', '30')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum candidates per miner.', '50')
            ->addOption('pid', null, InputOption::VALUE_REQUIRED, 'Storage page for the created records.', '0')
            ->addOption('miners', null, InputOption::VALUE_REQUIRED, 'Comma-separated: recovery,reformulation,translation', 'recovery,reformulation,translation')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the proposals, write nothing.');
    }

    /**
     * Terme, ueber die schon entschieden ist — in BEIDE Quellen geschaut:
     *
     *  • indexSettings()->synonyms deckt die kuratierte YAML-Basis UND die
     *    aktiven DB-Zeilen ab. Ohne diesen Blick schlaegt der Miner vor,
     *    was in der settings.yaml langst steht (der DB-Check allein sieht
     *    die YAML-Haelfte des Woerterbuchs nicht).
     *  • exists() deckt zusaetzlich Kandidaten und *abgelehnte* Zeilen ab.
     *    Eine Ablehnung muss halten: sonst schlaegt jeder Lauf erneut vor,
     *    was ein Mensch schon verworfen hat, und die Liste konvergiert nie.
     *
     * @param array<string,mixed> $knownFromSettings
     */
    private function isDecided(Site $site, string $term, array $knownFromSettings): bool
    {
        $needle = mb_strtolower(trim($term));
        if ($needle === '') {
            return true;
        }
        if (isset($knownFromSettings[$needle])) {
            return true;
        }
        return $this->dictionary->exists($site->getIdentifier(), DictionaryRepository::KIND_SYNONYM, $needle);
    }

    /**
     * @return array<string,mixed> lowercased synonym keys of the merged dictionary
     */
    private function knownSynonyms(Site $site): array
    {
        $out = [];
        foreach (array_keys($this->configProvider->indexSettings($site)->synonyms) as $key) {
            $out[mb_strtolower((string)$key)] = true;
        }
        return $out;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getOption('site');
        $days = max(1, (int)$input->getOption('days'));
        $limit = max(1, (int)$input->getOption('limit'));
        $storagePid = max(0, (int)$input->getOption('pid'));
        $dry = (bool)$input->getOption('dry-run');
        $miners = array_filter(array_map('trim', explode(',', (string)$input->getOption('miners'))));
        $cutoff = time() - $days * 86400;

        $written = 0;
        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($only !== null && $site->getIdentifier() !== (string)$only) {
                continue;
            }
            if (trim((string)$site->getSettings()->get('meilisearch.url', '')) === '') {
                continue;
            }
            $io->section($site->getIdentifier());
            $known = $this->knownSynonyms($site);

            if (in_array('recovery', $miners, true)) {
                $written += $this->mineRecovery($io, $site, $cutoff, $limit, $storagePid, $dry, $known);
            }
            if (in_array('reformulation', $miners, true)) {
                $written += $this->mineReformulations($io, $site, $cutoff, $limit, $storagePid, $dry, $known);
            }
            if (in_array('translation', $miners, true)) {
                $written += $this->mineTranslations($io, $site, $limit, $storagePid, $dry, $known);
            }
        }

        if ($dry) {
            $io->note('Dry run — nothing was written.');
            return Command::SUCCESS;
        }
        $io->success(sprintf(
            '%d candidate(s) written. Review them in the backend (list module, "Search dictionary"), set the good ones to "active" and run ws_meilisearch:apply-settings.',
            $written,
        ));
        return Command::SUCCESS;
    }

    /**
     * Miner 1: what the runtime recovery ladder already knows.
     */
    private function mineRecovery(SymfonyStyle $io, Site $site, int $cutoff, int $limit, int $storagePid, bool $dry, array $known): int
    {
        $rows = $this->zeroResultQueries($site->getIdentifier(), $cutoff, $limit);
        if ($rows === []) {
            $io->writeln('recovery: no zero-result queries in the window.');
            return 0;
        }
        $written = 0;
        foreach ($rows as $row) {
            $query = (string)$row['query'];
            $alternatives = $this->recovery->recover(
                $site,
                $query,
                null,
                null,
                $this->highlightAttributes($site),
                3,
            );
            foreach ($alternatives as $alternative) {
                // A relaxed query is not a vocabulary fact — it just drops
                // words. Nothing to write down.
                if ($alternative->kind === QueryAlternative::KIND_RELAXED
                    || $alternative->kind === QueryAlternative::KIND_DROPPED) {
                    continue;
                }
                $kind = DictionaryRepository::KIND_SYNONYM;
                $evidence = sprintf(
                    '%dx 0 hits (last %s); "%s" has %d hits [%s]',
                    (int)$row['hits'],
                    date('d.m.Y', (int)$row['last_seen']),
                    $alternative->query,
                    $alternative->totalHits,
                    $alternative->kind,
                );
                // Ein echter Lauf wuerde bekannte Terme ueberspringen — der
                // Trockenlauf muss dasselbe zeigen, sonst schlaegt er bei
                // jedem Aufruf erneut vor, was langst entschieden ist.
                if ($this->isDecided($site, $query, $known)) {
                    continue;
                }
                $io->writeln(sprintf(
                    '  recovery: <info>%s</info> → %s (%d hits, %s)',
                    $query,
                    $alternative->query,
                    $alternative->totalHits,
                    $alternative->kind,
                ));
                if (!$dry && $this->dictionary->addCandidate(
                    $site->getIdentifier(),
                    $kind,
                    $query,
                    [$alternative->query],
                    DictionaryRepository::SOURCE_VOCABULARY,
                    $evidence,
                    $alternative->totalHits,
                    $storagePid,
                )) {
                    $written++;
                }
                // One proposal per failing query keeps the review list
                // readable; the next run picks up the rest if the first
                // one was rejected.
                break;
            }
        }
        return $written;
    }

    /**
     * Miner 2: zero-result query followed by a similar query that worked.
     */
    private function mineReformulations(SymfonyStyle $io, Site $site, int $cutoff, int $limit, int $storagePid, bool $dry, array $known): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_wsmeilisearch_search_log');
        $rows = $qb->select('query', 'language_id', 'result_count', 'crdate')
            ->from('tx_wsmeilisearch_search_log')
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($site->getIdentifier())),
                $qb->expr()->eq('source', $qb->createNamedParameter('search')),
                $qb->expr()->gte('crdate', $qb->createNamedParameter($cutoff, ParameterType::INTEGER)),
            )
            ->orderBy('crdate', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $written = 0;
        $seen = [];
        foreach ($rows as $i => $row) {
            if ((int)$row['result_count'] !== 0) {
                continue;
            }
            $failed = (string)$row['query'];
            for ($j = $i + 1; $j < count($rows); $j++) {
                $next = $rows[$j];
                if ((int)$next['crdate'] - (int)$row['crdate'] > self::REFORMULATION_WINDOW) {
                    break;
                }
                if ((int)$next['language_id'] !== (int)$row['language_id'] || (int)$next['result_count'] < 1) {
                    continue;
                }
                $success = (string)$next['query'];
                if ($success === $failed || !$this->looksRelated($failed, $success)) {
                    continue;
                }
                $key = $failed . '=>' . $success;
                if (isset($seen[$key])) {
                    break;
                }
                $seen[$key] = true;
                if ($this->isDecided($site, $failed, $known)) {
                    break;
                }
                $io->writeln(sprintf(
                    '  reformulation: <info>%s</info> → %s (%d hits, %ds later)',
                    $failed,
                    $success,
                    (int)$next['result_count'],
                    (int)$next['crdate'] - (int)$row['crdate'],
                ));
                if (!$dry && $this->dictionary->addCandidate(
                    $site->getIdentifier(),
                    DictionaryRepository::KIND_SYNONYM,
                    $failed,
                    [$success],
                    DictionaryRepository::SOURCE_LOG,
                    sprintf(
                        '0 hits on %s, %ds later "%s" with %d hits',
                        date('d.m.Y H:i', (int)$row['crdate']),
                        (int)$next['crdate'] - (int)$row['crdate'],
                        $success,
                        (int)$next['result_count'],
                    ),
                    (int)$next['result_count'],
                    $storagePid,
                )) {
                    $written++;
                }
                break;
            }
            if (count($seen) >= $limit) {
                break;
            }
        }
        if ($seen === []) {
            $io->writeln('reformulation: no matching pairs in the window.');
        }
        return $written;
    }

    /**
     * Miner 3: single-word title pairs across the site's languages.
     */
    private function mineTranslations(SymfonyStyle $io, Site $site, int $limit, int $storagePid, bool $dry, array $known): int
    {
        $default = $this->singleWordTitles(0);
        $written = 0;
        $pairs = 0;
        // Dieselbe Titelpaarung entsteht so oft, wie es Seiten mit diesem
        // Titel gibt — in einer Knowledge-Base sind "Einstellungen" /
        // "Settings" Dutzende Seiten. Ohne Dedupe frisst ein einziges
        // Wortpaar das komplette --limit und die Vorschlagsliste besteht
        // aus Wiederholungen.
        $seenPairs = [];
        foreach ($site->getAllLanguages() as $language) {
            $languageId = $language->getLanguageId();
            if ($languageId === 0) {
                continue;
            }
            foreach ($this->singleWordTitles($languageId) as $parent => $translated) {
                if (!isset($default[$parent])) {
                    continue;
                }
                $source = mb_strtolower($default[$parent]);
                $target = mb_strtolower($translated);
                if ($source === $target || $pairs >= $limit) {
                    continue;
                }
                // Short titles are navigation labels, not vocabulary
                // ("Suche", "Shop", "Profil") — as synonyms they would
                // only blur the result list.
                if (mb_strlen($source) < 6 || mb_strlen($target) < 6) {
                    continue;
                }
                $pairKey = $source . '|' . $target;
                if (isset($seenPairs[$pairKey])) {
                    continue;
                }
                $seenPairs[$pairKey] = true;
                // Pairs this close together (profil/profile, autor/author)
                // are already handled by Meilisearch's typo tolerance; a
                // synonym for them is dead weight in the review list.
                if (levenshtein($source, $target) <= 2) {
                    continue;
                }
                if ($this->isDecided($site, $source, $known) && $this->isDecided($site, $target, $known)) {
                    continue;
                }
                $pairs++;
                $io->writeln(sprintf('  translation: <info>%s</info> ↔ %s (lang %d)', $source, $target, $languageId));
                if ($dry) {
                    continue;
                }
                // Both directions, because a visitor may type either one.
                foreach ([[$source, $target], [$target, $source]] as [$term, $replacement]) {
                    if ($this->dictionary->addCandidate(
                        $site->getIdentifier(),
                        DictionaryRepository::KIND_SYNONYM,
                        $term,
                        [$replacement],
                        DictionaryRepository::SOURCE_TRANSLATION,
                        sprintf('Title pair from page tree (language %d)', $languageId),
                        0,
                        $storagePid,
                    )) {
                        $written++;
                    }
                }
            }
        }
        if ($pairs === 0) {
            $io->writeln('translation: no single-word title pairs found.');
        }
        return $written;
    }

    /**
     * Single-word page titles per language, keyed by the default-language
     * uid so the two sides can be paired. Multi-word titles are skipped on
     * purpose: "Detecting Building" ↔ "Gebäude erfassen" is a sentence, not
     * a term, and as a synonym it would only add noise.
     *
     * @return array<int,string>
     */
    private function singleWordTitles(int $languageId): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $rows = $qb->select('uid', 'l10n_parent', 'title')
            ->from('pages')
            ->where(
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, ParameterType::INTEGER)),
                $qb->expr()->eq('hidden', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                $qb->expr()->notLike('title', $qb->createNamedParameter('% %')),
            )
            ->executeQuery()
            ->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $title = trim((string)$row['title']);
            // Skip anything that is not a plain word — navigation labels
            // like "404" or "–" are not vocabulary.
            if (mb_strlen($title) < 4 || preg_match('/^[\p{L}][\p{L}\p{N}\-]+$/u', $title) !== 1) {
                continue;
            }
            $key = $languageId === 0 ? (int)$row['uid'] : (int)$row['l10n_parent'];
            if ($key > 0) {
                $out[$key] = $title;
            }
        }
        return $out;
    }

    /**
     * Distinct zero-result queries with their frequency.
     *
     * @return list<array<string,mixed>>
     */
    private function zeroResultQueries(string $siteIdentifier, int $cutoff, int $limit): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_wsmeilisearch_search_log');
        $qb->getRestrictions()->removeAll();
        $qb->addSelectLiteral('query', 'COUNT(*) AS hits', 'MAX(crdate) AS last_seen')
            ->from('tx_wsmeilisearch_search_log')
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('source', $qb->createNamedParameter('search')),
                $qb->expr()->eq('result_count', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                $qb->expr()->gte('crdate', $qb->createNamedParameter($cutoff, ParameterType::INTEGER)),
            )
            ->groupBy('query')
            ->orderBy('hits', 'DESC')
            ->addOrderBy('last_seen', 'DESC')
            ->setMaxResults($limit);
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Is the successful query plausibly a rewrite of the failed one? Three
     * cheap tests, any of which is enough: shared token, shared prefix of
     * four characters, or a small edit distance. Without this the miner
     * would pair a failed search with whatever the next visitor happened
     * to look for.
     */
    private function looksRelated(string $failed, string $success): bool
    {
        $failedTokens = preg_split('/\s+/u', $failed) ?: [];
        $successTokens = preg_split('/\s+/u', $success) ?: [];
        foreach ($failedTokens as $token) {
            if (mb_strlen($token) >= 4 && in_array($token, $successTokens, true)) {
                return true;
            }
        }
        if (mb_substr($failed, 0, 4) === mb_substr($success, 0, 4)) {
            return true;
        }
        $distance = levenshtein(mb_substr($failed, 0, 60), mb_substr($success, 0, 60));
        return $distance > 0 && $distance <= max(2, (int)floor(mb_strlen($failed) / 4));
    }

    /**
     * @return list<string>
     */
    private function highlightAttributes(Site $site): array
    {
        $fields = $this->configProvider->highlightAttributes($site);
        if ($fields === []) {
            $fields = ['title', 'description', 'bodytext'];
        }
        if (!in_array('content', $fields, true)) {
            $fields[] = 'content';
        }
        return array_values($fields);
    }
}
