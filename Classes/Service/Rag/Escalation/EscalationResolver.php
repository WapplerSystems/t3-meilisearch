<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag\Escalation;

use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use WapplerSystems\Meilisearch\Event\RagEscalationEvent;

/**
 * Decides whether a chat turn offers a way to a human, and what that way
 * looks like.
 *
 * Every render path asks this class — the synchronous answer, the streamed
 * chat and the static shell — because the card used to be reachable on only
 * one of them: the streamed chat, which is what visitors actually use, renders
 * its shell before any answer exists, so it could never decide "no answer,
 * show the card". Keeping the decision in one place is what keeps the paths
 * from drifting apart again.
 *
 * Default rule, meilisearch.rag.fallback.show:
 *   always     — under every answer
 *   onlyEmpty  — only when the model could not ground its answer, i.e.
 *                status is not ok (no_context / failed / disabled / clarify)
 *                OR it answered without citing a single source
 *   never      — disabled
 * The default card is built from meilisearch.rag.fallback.{contactName,email,
 * phone,ticketUrl}. Both the rule and the card can be overridden per turn by
 * a listener of {@see RagEscalationEvent}, which is how a site package adds a
 * contact form in the visitor's language.
 */
final class EscalationResolver
{
    /** Per answer on the synchronous path; `always` shows it every time. */
    public const CONTEXT_ANSWER = 'answer';
    /**
     * Per answer on the streamed path. `always` is already satisfied there by
     * the static card below the transcript, so it does not show per turn —
     * otherwise every answered turn would grow a second copy of that card.
     */
    public const CONTEXT_STREAM = 'stream';
    /** The shell before any question; shows only in `always` mode. */
    public const CONTEXT_STATIC = 'static';

    private const LLL = 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * The card to render, or null when there is none — either because the
     * rule (or a listener) said no, or because nothing is configured to show.
     *
     * @param list<string> $citedIds
     */
    public function resolve(
        ?Site $site,
        ?int $languageId,
        string $context,
        string $status = '',
        array $citedIds = [],
        string $question = '',
        string $conversationId = '',
    ): ?Escalation {
        if (!$site instanceof Site) {
            return null;
        }
        $language = $this->siteLanguage($site, $languageId);
        $languageService = $language instanceof SiteLanguage
            ? $this->languageServiceFactory->createFromSiteLanguage($language)
            : $this->languageServiceFactory->create('default');

        $event = new RagEscalationEvent(
            site: $site,
            language: $language,
            status: $status,
            reason: $this->reason($context, $status, $citedIds),
            citedIds: $citedIds,
            question: $question,
            conversationId: $conversationId,
            escalation: $this->defaultEscalation($site, $languageService, $question),
            show: $this->defaultShow($site, $context, $status, $citedIds),
        );
        $this->eventDispatcher->dispatch($event);

        if (!$event->show || $event->escalation->isEmpty()) {
            return null;
        }

        return $this->substitutePlaceholders($event->escalation, $language, $question, $conversationId);
    }

    /**
     * @param list<string> $citedIds
     */
    private function defaultShow(Site $site, string $context, string $status, array $citedIds): bool
    {
        $mode = $this->mode($site);
        if ($context === self::CONTEXT_STATIC) {
            return $mode === 'always';
        }
        if ($context === self::CONTEXT_STREAM && $mode === 'always') {
            return false;
        }

        return match ($mode) {
            'always' => true,
            'never' => false,
            default => $status !== 'ok' || $citedIds === [],
        };
    }

    /**
     * @param list<string> $citedIds
     */
    private function reason(string $context, string $status, array $citedIds): string
    {
        if ($context === self::CONTEXT_STATIC) {
            return RagEscalationEvent::REASON_STATIC;
        }
        if ($status !== 'ok') {
            return $status;
        }

        return $citedIds === [] ? RagEscalationEvent::REASON_UNCITED : RagEscalationEvent::REASON_ANSWERED;
    }

    private function defaultEscalation(Site $site, LanguageService $languageService, string $question): Escalation
    {
        $settings = $site->getSettings();
        $contactName = trim((string)$settings->get('meilisearch.rag.fallback.contactName', ''));
        $email = trim((string)$settings->get('meilisearch.rag.fallback.email', ''));
        $phone = trim((string)$settings->get('meilisearch.rag.fallback.phone', ''));
        $ticketUrl = trim((string)$settings->get('meilisearch.rag.fallback.ticketUrl', ''));

        $actions = [];
        if ($email !== '') {
            // The question goes into the subject so the recipient knows what
            // was asked without having to ask back.
            $subject = $languageService->sL(self::LLL . 'rag.fallback.email.subject');
            $subject = $question !== '' ? $subject . ': ' . $question : $subject;
            $actions[] = EscalationAction::email(
                $languageService->sL(self::LLL . 'rag.fallback.email'),
                $email,
                'mailto:' . $email . '?subject=' . rawurlencode($subject),
            );
        }
        if ($phone !== '') {
            $actions[] = EscalationAction::phone($languageService->sL(self::LLL . 'rag.fallback.phone'), $phone);
        }
        if ($ticketUrl !== '') {
            $actions[] = EscalationAction::link($languageService->sL(self::LLL . 'rag.fallback.ticket'), $ticketUrl, true);
        }

        return new Escalation(
            $contactName !== '' ? $contactName : $languageService->sL(self::LLL . 'rag.fallback.heading'),
            $languageService->sL(self::LLL . 'rag.fallback.intro'),
            $actions,
        );
    }

    /**
     * Placeholders are substituted only in hrefs and always URL-encoded: the
     * values are visitor input ({question}) or end up in a query string, and
     * an unencoded "&" in a question would otherwise inject a parameter.
     */
    private function substitutePlaceholders(Escalation $escalation, ?SiteLanguage $language, string $question, string $conversationId): Escalation
    {
        $replacements = [
            '{conversationId}' => rawurlencode($conversationId),
            '{languageId}' => (string)($language?->getLanguageId() ?? 0),
            '{language}' => rawurlencode($language?->getLocale()->getLanguageCode() ?? ''),
            '{question}' => rawurlencode($question),
        ];
        $actions = [];
        foreach ($escalation->actions as $action) {
            $action = $action->withHref(strtr($action->href, $replacements));
            if (self::isSafeHref($action->href)) {
                $actions[] = $action;
            }
        }

        return $escalation->withActions($actions);
    }

    /**
     * Hrefs come from site settings and third-party listeners and end up
     * verbatim in an <a href> on both render paths. Only the schemes a contact
     * card has any business with pass; a `javascript:` URL is dropped rather
     * than rendered as a clickable button.
     */
    private static function isSafeHref(string $href): bool
    {
        $href = trim($href);
        if ($href === '') {
            return false;
        }
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $href, $match) !== 1) {
            return true; // relative URL
        }

        return in_array(strtolower($match[1]), ['http', 'https', 'mailto', 'tel'], true);
    }

    private function siteLanguage(Site $site, ?int $languageId): ?SiteLanguage
    {
        if ($languageId === null) {
            return null;
        }
        try {
            return $site->getLanguageById($languageId);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function mode(Site $site): string
    {
        return strtolower(trim((string)$site->getSettings()->get('meilisearch.rag.fallback.show', 'onlyEmpty')));
    }
}
