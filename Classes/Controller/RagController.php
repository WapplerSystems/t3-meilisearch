<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use WapplerSystems\Meilisearch\Service\AccessControlFilter;
use WapplerSystems\Meilisearch\Service\Rag\Conversation;
use WapplerSystems\Meilisearch\Service\Rag\ConversationStore;
use WapplerSystems\Meilisearch\Service\Rag\FallbackContact;
use WapplerSystems\Meilisearch\Service\Rag\RagService;
use WapplerSystems\Meilisearch\Service\Rag\CitationRenderer;
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
        private readonly AccessControlFilter $accessControlFilter,
        private readonly FallbackContact $fallbackContact,
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
        // The initial form has no answer to anchor a fallback on, so
        // only show the contact card when the operator explicitly
        // wants it always-on. In onlyEmpty mode the streamed path now
        // decides per answer instead (FallbackContact::shouldStream),
        // which is what the static card here cannot do.
        $this->view->assignMultiple([
            'question' => $q,
            'conversation' => $conversation->turns,
            'conversationEnabled' => $this->conversationEnabled($site),
            'fallback' => $this->fallbackContact->resolve($site),
            'showFallback' => $this->fallbackContact->isAlways($site),
            'pageType' => $this->currentPageType(),
            'streamEndpoint' => $this->streamEndpoint(),
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
        // Pin the LLM answer language to the active FE site language so
        // the model doesn't drift to English when context excerpts come
        // back in a mix of languages or when the question itself is
        // short / language-ambiguous. Independent of the retrieval
        // language filter above (a visitor on /de/ wants a German answer
        // even when restrictToCurrentLanguage is off).
        if ($languageId !== null) {
            $options['language'] = $languageId;
        }
        // FE-access-control: retrieval is scoped to docs the visitor is
        // allowed to see, so the LLM never grounds in restricted
        // material it then re-emits as a citation. Same filter the FE
        // search uses; uses the global PSR-7 request because Extbase
        // strips request attributes before the controller sees them.
        $accessReq = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $existingFilters = isset($options['filters']) && is_array($options['filters']) ? $options['filters'] : [];
        $options['filters'] = $this->accessControlFilter->applyTo($existingFilters, $site, $accessReq);

        $answer = $this->ragService->ask($site, $q, $options);

        // Persist answered turns and clarification turns alike: the reply to a
        // clarifying question needs the question in history so the query
        // rewriter can resolve it on the next turn. The kind lets the triage
        // step avoid asking for clarification twice in a row.
        if (($answer->status === 'ok' || $answer->status === 'clarify') && $this->conversationEnabled($site)) {
            $kind = $answer->status === 'clarify' ? Turn::KIND_CLARIFICATION : Turn::KIND_ANSWER;
            $turn = new Turn(
                $q,
                $answer->answer,
                $answer->citedIds,
                $kind,
                // Only the cited documents, so the transcript can render its
                // references again after a reload.
                CitationRenderer::citationsFor($answer->sources, $answer->citedIds),
                // Same for the buttons under the answer.
                $answer->suggestions,
            );
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
            'fallback' => $this->fallbackContact->resolve($site),
            'showFallback' => $this->fallbackContact->shouldShow($site, $answer->status, $answer->citedIds),
            'pageType' => $this->currentPageType(),
            'streamEndpoint' => $this->streamEndpoint(),
        ]);
        return $this->htmlResponse();
    }

    public function resetAction(): ResponseInterface
    {
        $site = $this->resolveSite();
        if ($site instanceof Site && $this->conversationEnabled($site)) {
            $this->conversationStore->clear($this->request, $this->sessionKey($site));
        }
        // Preserve the current page type so a reset inside the bare chat-widget
        // embed stays in the embed instead of bouncing to the full page (type 0).
        $pageType = $this->currentPageType();
        if ($pageType > 0) {
            $uri = $this->uriBuilder->reset()->setTargetPageType($pageType)->uriFor('form');
            return $this->redirectToUri($uri);
        }
        return $this->redirect('form');
    }

    /**
     * Current frontend page type (typeNum). Used to keep the bare chat-widget
     * embed (a dedicated typeNum) sticky across the plugin's own GET form
     * submit and action links — otherwise they default to type 0 and the
     * iframe reloads the full page. Read from the global request because
     * Extbase strips routing attributes off $this->request.
     */
    /**
     * Streaming endpoint for the rendered shell, prefixed with the active
     * language base ("/de/_ws_meilisearch/rag/stream"). The path is what tells
     * RagStreamMiddleware which language to retrieve in — without it the site
     * resolver finds no SiteLanguage and the stream searches across every
     * language, ignoring meilisearch.restrictToCurrentLanguage.
     */
    private function streamEndpoint(): string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $language = $request instanceof ServerRequestInterface ? $request->getAttribute('language') : null;
        $prefix = $language instanceof SiteLanguage ? rtrim($language->getBase()->getPath(), '/') : '';

        return $prefix . '/_ws_meilisearch/rag/stream';
    }

    private function currentPageType(): int
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $routing = $request instanceof ServerRequestInterface ? $request->getAttribute('routing') : null;
        if ($routing instanceof PageArguments) {
            return (int)$routing->getPageType();
        }
        return 0;
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
