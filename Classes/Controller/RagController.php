<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use WapplerSystems\Meilisearch\Service\Rag\Conversation;
use WapplerSystems\Meilisearch\Service\Rag\ConversationStore;
use WapplerSystems\Meilisearch\Service\Rag\RagService;
use WapplerSystems\Meilisearch\Service\Rag\Turn;

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
        private readonly ConversationStore $conversationStore,
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
        $site = $this->resolveSite();
        $conversation = $this->loadConversation($site);
        $this->view->assignMultiple([
            'question' => $q,
            'conversation' => $conversation->turns,
            'conversationEnabled' => $this->conversationEnabled($site),
        ]);
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

        $conversation = $this->loadConversation($site);
        $answer = $this->ragService->ask($site, $q, [
            'conversation' => $conversation,
        ]);

        if ($answer->status === 'ok' && $this->conversationEnabled($site)) {
            $turn = new Turn($q, $answer->answer, $answer->citedIds);
            $maxTurns = max(1, (int)$site->getSettings()->get('meilisearch.rag.conversation.maxTurns', 3));
            $conversation = $conversation->withTurn($turn, $maxTurns);
            $sessionKey = $this->sessionKey($site);
            $this->conversationStore->save($this->request, $sessionKey, $conversation);
        }

        $this->view->assignMultiple([
            'question' => $q,
            'answer' => $answer,
            'conversation' => $conversation->turns,
            'conversationEnabled' => $this->conversationEnabled($site),
        ]);
        return $this->htmlResponse();
    }

    public function resetAction(): ResponseInterface
    {
        $site = $this->resolveSite();
        if ($site instanceof Site && $this->conversationEnabled($site)) {
            $this->conversationStore->clear($this->request, $this->sessionKey($site));
        }
        return $this->redirect('form');
    }

    private function conversationEnabled(?Site $site): bool
    {
        if (!$site instanceof Site) {
            return false;
        }
        return (bool)$site->getSettings()->get('meilisearch.rag.conversation.enabled', false);
    }

    private function sessionKey(Site $site): string
    {
        $key = trim((string)$site->getSettings()->get(
            'meilisearch.rag.conversation.sessionKey',
            'ws_meilisearch_rag_conversation',
        ));
        return $key !== '' ? $key : 'ws_meilisearch_rag_conversation';
    }

    private function loadConversation(?Site $site): Conversation
    {
        if (!$this->conversationEnabled($site)) {
            return Conversation::empty();
        }
        return $this->conversationStore->load($this->request, $this->sessionKey($site));
    }
}
