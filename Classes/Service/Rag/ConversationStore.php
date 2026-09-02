<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * Reads / writes Conversation state from the anonymous frontend user
 * session. TYPO3 lazily issues a session cookie the first time we call
 * setSessionData(), so there's no upfront opt-in required from the site
 * package — but if the request was made without a frontend user attribute
 * (e.g. CLI ad-hoc tests via the ws_meilisearch:ask command), all calls
 * become no-ops and the conversation degrades to single-turn.
 */
final class ConversationStore
{
    public function load(ServerRequestInterface $request, string $sessionKey): Conversation
    {
        $user = $this->frontendUser($request);
        if ($user === null) {
            return Conversation::empty();
        }
        $raw = $user->getSessionData($sessionKey);
        if (!is_array($raw)) {
            return Conversation::empty();
        }
        return Conversation::fromArray($raw);
    }

    public function save(ServerRequestInterface $request, string $sessionKey, Conversation $conversation): void
    {
        $user = $this->frontendUser($request);
        if ($user === null) {
            return;
        }
        $user->setAndSaveSessionData($sessionKey, $conversation->toArray());
    }

    /**
     * Like {@see save()}, but also fixates an anonymous session so it can be
     * announced to the client.
     *
     * setAndSaveSessionData() writes the row and nothing else. Fixating a new
     * anonymous session — and with it the core's decision to send a session
     * cookie — happens in FrontendUserAuthentication::storeSessionData(),
     * which the frontend normally reaches at the end of a request. The
     * streaming endpoint flushes its own headers long before that, so it needs
     * a session that is persisted *and* marked to be announced before the
     * first frame goes out.
     *
     * On a visitor who already has a session this simply updates it; the core
     * then has no new cookie to send, which is correct.
     */
    public function establish(ServerRequestInterface $request, string $sessionKey, Conversation $conversation): void
    {
        $user = $this->frontendUser($request);
        if ($user === null) {
            return;
        }
        $user->setSessionData($sessionKey, $conversation->toArray());
        $user->storeSessionData();
    }

    public function clear(ServerRequestInterface $request, string $sessionKey): void
    {
        $user = $this->frontendUser($request);
        if ($user === null) {
            return;
        }
        // setAndSaveSessionData with an empty array drops the key for the
        // next read while keeping the session itself alive.
        $user->setAndSaveSessionData($sessionKey, null);
    }

    private function frontendUser(ServerRequestInterface $request): ?FrontendUserAuthentication
    {
        $user = $request->getAttribute('frontend.user');
        return $user instanceof FrontendUserAuthentication ? $user : null;
    }
}
