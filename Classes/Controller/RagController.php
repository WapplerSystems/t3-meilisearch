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
        // The initial form has no answer to anchor a fallback on, so
        // only show the contact card when the operator explicitly
        // wants it always-on. onlyEmpty/never both render nothing here.
        $mode = $site instanceof Site
            ? strtolower(trim((string)$site->getSettings()->get('meilisearch.rag.fallback.show', 'onlyEmpty')))
            : 'never';
        $this->view->assignMultiple([
            'question' => $q,
            'conversation' => $conversation->turns,
            'conversationEnabled' => $this->conversationEnabled($site),
            'fallback' => $this->resolveFallback($site),
            'showFallback' => $mode === 'always',
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
            'fallback' => $this->resolveFallback($site),
            'showFallback' => $this->shouldShowFallback($site, $answer->status, $answer->citedIds),
        ]);
        return $this->htmlResponse();
    }

    /**
     * Pull the four optional fallback fields from site settings and
     * pre-compute the tel: href (strip spaces / dashes so a number
     * like "0241 / 88 98 01" still produces a valid dialer link).
     *
     * @return array{contactName:string,email:string,phone:string,telHref:string,ticketUrl:string}
     */
    private function resolveFallback(?Site $site): array
    {
        if (!$site instanceof Site) {
            return ['contactName' => '', 'email' => '', 'phone' => '', 'telHref' => '', 'ticketUrl' => ''];
        }
        $settings = $site->getSettings();
        $phone = trim((string)$settings->get('meilisearch.rag.fallback.phone', ''));
        return [
            'contactName' => trim((string)$settings->get('meilisearch.rag.fallback.contactName', '')),
            'email' => trim((string)$settings->get('meilisearch.rag.fallback.email', '')),
            'phone' => $phone,
            // tel: links need a dial-friendly value — strip everything
            // except digits, leading +, and pause/wait extensions.
            'telHref' => $phone !== '' ? (string)preg_replace('/[^\d+]/', '', $phone) : '',
            'ticketUrl' => trim((string)$settings->get('meilisearch.rag.fallback.ticketUrl', '')),
        ];
    }

    /**
     * Decide whether the fallback partial renders. Driven by
     * meilisearch.rag.fallback.show:
     *   always     — under every answer
     *   onlyEmpty  — only when the model couldn't ground in sources
     *                (status no_context / failed, OR ok-but-no-citations)
     *   never      — disabled
     *
     * @param list<string> $citedIds
     */
    private function shouldShowFallback(?Site $site, string $status, array $citedIds): bool
    {
        if (!$site instanceof Site) {
            return false;
        }
        $mode = strtolower(trim((string)$site->getSettings()->get('meilisearch.rag.fallback.show', 'onlyEmpty')));
        return match ($mode) {
            'always' => true,
            'never' => false,
            default => $status !== 'ok' || $citedIds === [],
        };
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
