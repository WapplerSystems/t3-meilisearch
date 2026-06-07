<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller\Backend;

use Doctrine\DBAL\ParameterType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use WapplerSystems\Meilisearch\Controller\Backend\Support\BackendContext;
use WapplerSystems\Meilisearch\Service\RagTest\RagTestResult;
use WapplerSystems\Meilisearch\Service\RagTest\RagTestRunner;

/**
 * The "RAG tests" tab + actions to run them on demand:
 *
 *   - ragtests      GET   table of every test row with current state
 *                          + Run-now / Run-all buttons + List-module
 *                          deep links
 *   - runRagTest    POST  run one row by uid, persist result, flash
 *                          a pass/fail/error summary
 *   - runAllRagTests POST run every enabled row (same as the
 *                          scheduler / CLI), flash an aggregate
 *
 * RagTestRunner is already shared with the CLI + scheduler so no
 * duplication of the run logic; this controller is just BE plumbing.
 */
final class RagTestController
{
    private const TABLE = RagTestRunner::TABLE;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly ConnectionPool $connectionPool,
        private readonly RagTestRunner $runner,
        private readonly BackendContext $context,
    ) {}

    public function handle(ServerRequestInterface $request, string $action): ResponseInterface
    {
        return match ($action) {
            'runRagTest'     => $this->runOne($request),
            'runAllRagTests' => $this->runAll($request),
            default          => $this->index($request),
        };
    }

    private function index(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $rows = $this->loadRows();
        $summary = $this->summarise($rows);

        $moduleTemplate->assignMultiple([
            'tests' => $rows,
            'summary' => $summary,
            'runOneUrl' => $this->context->route('runRagTest'),
            'runAllUrl' => $this->context->route('runAllRagTests'),
            'newTestUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [self::TABLE => [0 => 'new']],
                'returnUrl' => $this->context->route('ragtests'),
            ]),
            'listEditUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('web_list', [
                'id' => 0,
                'table' => self::TABLE,
            ]),
            ...$this->context->tabNavData(),
        ]);
        return $moduleTemplate->renderResponse('Backend/Overview/RagTests');
    }

    private function runOne(ServerRequestInterface $request): ResponseInterface
    {
        if ($wrong = $this->context->requirePost($request, 'ragtests')) {
            return $wrong;
        }
        $uid = (int)(($request->getParsedBody() ?? [])['uid'] ?? 0);
        if ($uid <= 0) {
            $this->context->addFlash('Test uid missing.', ContextualFeedbackSeverity::ERROR);
            return $this->context->redirect('ragtests');
        }

        // The runner expects a list of rows, not a single uid — slice
        // the query manually so we reuse the same persist + score path
        // without a second public entry point.
        $row = $this->loadOneRow($uid);
        if ($row === null) {
            $this->context->addFlash(sprintf('Test #%d not found.', $uid), ContextualFeedbackSeverity::ERROR);
            return $this->context->redirect('ragtests');
        }

        try {
            // Run the whole filter set restricted to this uid via a
            // temporary filter on the row's site_identifier; if that
            // happens to match other rows too, we'd over-run. Cheaper
            // path: call the runner with a private one-row helper.
            // We expose runOne() on the runner below to avoid this.
            $result = $this->runner->runOne($uid);
        } catch (\Throwable $e) {
            $this->context->addFlash(sprintf('Test #%d failed to run: %s', $uid, $e->getMessage()), ContextualFeedbackSeverity::ERROR);
            return $this->context->redirect('ragtests');
        }

        $severity = match ($result->status) {
            RagTestResult::PASS  => ContextualFeedbackSeverity::OK,
            RagTestResult::FAIL  => ContextualFeedbackSeverity::WARNING,
            default              => ContextualFeedbackSeverity::ERROR,
        };
        $this->context->addFlash($this->describe($row['title'] ?? '', $result), $severity);
        return $this->context->redirect('ragtests');
    }

    private function runAll(ServerRequestInterface $request): ResponseInterface
    {
        if ($wrong = $this->context->requirePost($request, 'ragtests')) {
            return $wrong;
        }
        try {
            $results = $this->runner->runAll();
        } catch (\Throwable $e) {
            $this->context->addFlash('Run-all failed: ' . $e->getMessage(), ContextualFeedbackSeverity::ERROR);
            return $this->context->redirect('ragtests');
        }
        if ($results === []) {
            $this->context->addFlash('No enabled tests to run.', ContextualFeedbackSeverity::WARNING);
            return $this->context->redirect('ragtests');
        }
        $passes = 0;
        $fails = 0;
        $errors = 0;
        foreach ($results as $entry) {
            $status = $entry['result']->status;
            match ($status) {
                RagTestResult::PASS  => $passes++,
                RagTestResult::FAIL  => $fails++,
                RagTestResult::ERROR => $errors++,
                default              => null,
            };
        }
        $severity = $fails > 0
            ? ContextualFeedbackSeverity::WARNING
            : ($errors > 0 ? ContextualFeedbackSeverity::INFO : ContextualFeedbackSeverity::OK);
        $this->context->addFlash(
            sprintf('Ran %d test(s): %d passed, %d failed, %d errored.', count($results), $passes, $fails, $errors),
            $severity,
        );
        return $this->context->redirect('ragtests');
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadRows(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());
        return $qb->select(
            'uid',
            'title',
            'question',
            'expected_answer',
            'similarity_threshold',
            'site_identifier',
            'hidden',
            'last_status',
            'last_score',
            'last_run_at',
            'last_actual_answer',
            'last_error',
        )
            ->from(self::TABLE)
            ->orderBy('hidden', 'ASC')
            ->addOrderBy('last_run_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadOneRow(int $uid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $row = $qb->select('uid', 'title')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        return $row === false ? null : $row;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{total:int, enabled:int, pass:int, fail:int, error:int, never:int}
     */
    private function summarise(array $rows): array
    {
        $sum = ['total' => count($rows), 'enabled' => 0, 'pass' => 0, 'fail' => 0, 'error' => 0, 'never' => 0];
        foreach ($rows as $row) {
            if ((int)$row['hidden'] === 0) {
                $sum['enabled']++;
            }
            $status = (string)$row['last_status'];
            if ($status === RagTestResult::PASS) {
                $sum['pass']++;
            } elseif ($status === RagTestResult::FAIL) {
                $sum['fail']++;
            } elseif ($status === RagTestResult::ERROR) {
                $sum['error']++;
            } else {
                $sum['never']++;
            }
        }
        return $sum;
    }

    private function describe(string $title, RagTestResult $result): string
    {
        return match ($result->status) {
            RagTestResult::PASS => sprintf('"%s" passed (score %.3f).', $title, $result->score),
            RagTestResult::FAIL => sprintf('"%s" failed (score %.3f below threshold).', $title, $result->score),
            default             => sprintf('"%s" errored: %s', $title, $result->error),
        };
    }
}
