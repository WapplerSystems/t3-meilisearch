<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * The "ask a human" contact card shown under a RAG answer, and the rule that
 * decides when it appears.
 *
 * Extracted from RagController because the card was reachable on ONE of the
 * two answer paths. The controller renders it per answer, but the streamed
 * chat — which is what the visitor actually uses, and the only path a chat
 * widget has — renders its shell BEFORE any answer exists, so with the
 * default `onlyEmpty` mode the shell could only ever decide "no answer yet,
 * show nothing". A visitor who ran into "I have no information about that"
 * in the widget was offered nothing at all. Both paths now ask this class.
 *
 * Driven by meilisearch.rag.fallback.show:
 *   always     — under every answer
 *   onlyEmpty  — only when the model could not ground its answer, i.e.
 *                status is not ok (no_context / failed / disabled / clarify)
 *                OR it answered without citing a single source
 *   never      — disabled
 */
final class FallbackContact
{
    /**
     * Pull the four optional contact fields out of site settings and
     * pre-compute the tel: href (strip spaces / dashes so a number like
     * "0241 / 88 98 01" still produces a valid dialer link).
     *
     * @return array{contactName:string,email:string,phone:string,telHref:string,ticketUrl:string}
     */
    public function resolve(?Site $site): array
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
            'telHref' => $phone !== '' ? (string)preg_replace('/[^\d+]/', '', $phone) : '',
            'ticketUrl' => trim((string)$settings->get('meilisearch.rag.fallback.ticketUrl', '')),
        ];
    }

    /**
     * Whether the card belongs under an answer with this status and this many
     * citations. See the class docblock for the modes.
     *
     * @param list<string> $citedIds
     */
    public function shouldShow(?Site $site, string $status, array $citedIds): bool
    {
        return match ($this->mode($site)) {
            'always' => true,
            'never' => false,
            default => $status !== 'ok' || $citedIds === [],
        };
    }

    /**
     * Same decision for the streamed path, minus the `always` mode.
     *
     * `always` is already satisfied there: the shell renders the card once,
     * statically, below the transcript (Form.html reads `showFallback`). If
     * this returned true for `always` as well, every answered turn would grow
     * a second copy of the same card. What the streamed path is missing — and
     * all it is missing — is the per-answer decision of `onlyEmpty`.
     *
     * @param list<string> $citedIds
     */
    public function shouldStream(?Site $site, string $status, array $citedIds): bool
    {
        if ($this->mode($site) === 'always') {
            return false;
        }

        return $this->shouldShow($site, $status, $citedIds);
    }

    /**
     * Whether anything is configured to show. The Fluid partial checks this
     * for itself; the streamed path needs it before it emits a frame, so an
     * unconfigured site does not send an empty card to every visitor.
     *
     * @param array{contactName:string,email:string,phone:string,telHref:string,ticketUrl:string} $fallback
     */
    public function hasContact(array $fallback): bool
    {
        return $fallback['email'] !== '' || $fallback['phone'] !== '' || $fallback['ticketUrl'] !== '';
    }

    /**
     * `always` mode: the card is permanently visible and needs no answer to
     * anchor it. This is the only mode under which the streamed shell renders
     * the card statically — see shouldStream().
     */
    public function isAlways(?Site $site): bool
    {
        return $this->mode($site) === 'always';
    }

    private function mode(?Site $site): string
    {
        if (!$site instanceof Site) {
            return 'never';
        }

        return strtolower(trim((string)$site->getSettings()->get('meilisearch.rag.fallback.show', 'onlyEmpty')));
    }
}
