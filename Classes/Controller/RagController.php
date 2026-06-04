<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
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
        $options = ['conversation' => $conversation];

        // Scope retrieval to the active site language. Without it, FileSchema-
        // Provider's per-(file, language) documents flood the top-K context
        // with N copies of the same record — same gotcha the CLI AskCommand
        // has to address. Honour meilisearch.restrictToCurrentLanguage when
        // explicitly set; otherwise default to the active language so the
        // editor's UX is always the obvious one (the answer comes from
        // documents matching the visitor's language).
        $languageId = $this->resolveCurrentLanguageId();
        $restrict = (bool)$site->getSettings()->get('meilisearch.restrictToCurrentLanguage', true);
        if ($restrict && $languageId !== null) {
            $options['filters'] = ['language' => [$languageId]];
        }

        $answer = $this->ragService->ask($site, $q, $options);

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

    /**
     * Active site-language id. Extbase wraps the request and may strip
     * the `language` attribute, so fall back to the global PSR-7 request
     * (same pattern as SearchController::resolveCurrentLanguageId).
     */
    private function resolveCurrentLanguageId(): ?int
    {
        $language = $this->request->getAttribute('language');
        if ($language instanceof SiteLanguage) {
            return $language->getLanguageId();
        }
        $globalRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($globalRequest !== null) {
            $language = $globalRequest->getAttribute('language');
            if ($language instanceof SiteLanguage) {
                return $language->getLanguageId();
            }
        }
        return null;
    }
}
