<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use WapplerSystems\Meilisearch\Service\Rag\RagService;

/**
 * Frontend-facing RAG controller. Mirrors SearchController's GET-only, PRG
 * conventions so the chat URL is bookmarkable and the back button never
 * triggers re-submission warnings.
 *
 * Two actions:
 *  - form: empty input (initial render)
 *  - ask:  question submitted → calls RagService::ask and assigns the answer
 */
final class RagController extends ActionController
{
    public function __construct(
        private readonly RagService $ragService,
        private readonly SiteFinder $siteFinder,
    ) {}

    private function resolveSite(): ?Site
    {
        $site = $this->request->getAttribute('site');
        if ($site instanceof Site) {
            return $site;
        }
        $globalRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($globalRequest !== null) {
            $site = $globalRequest->getAttribute('site');
            if ($site instanceof Site) {
                return $site;
            }
            $pageInfo = $globalRequest->getAttribute('frontend.page.information');
            if ($pageInfo !== null && method_exists($pageInfo, 'getId')) {
                try {
                    return $this->siteFinder->getSiteByPageId((int)$pageInfo->getId());
                } catch (\Throwable) {
                    return null;
                }
            }
        }
        return null;
    }

    public function formAction(string $q = ''): ResponseInterface
    {
        $this->view->assign('question', $q);
        return $this->htmlResponse();
    }

    public function askAction(string $q = ''): ResponseInterface
    {
        if (strtoupper($this->request->getMethod()) === 'POST') {
            return $this->redirect('ask', null, null, ['q' => $q]);
        }

        $site = $this->resolveSite();
        if (!$site instanceof Site) {
            $this->view->assign('question', $q);
            return $this->htmlResponse();
        }

        $answer = $this->ragService->ask($site, $q);
        $this->view->assignMultiple([
            'question' => $q,
            'answer' => $answer,
        ]);
        return $this->htmlResponse();
    }
}
