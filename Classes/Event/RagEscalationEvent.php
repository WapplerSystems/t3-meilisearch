<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Event;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use WapplerSystems\Meilisearch\Service\Rag\Escalation\Escalation;

/**
 * Dispatched whenever the chat decides whether to offer a way to a human —
 * for every finished turn, on the synchronous and on the streamed path, and
 * once for the static card of `meilisearch.rag.fallback.show = always`.
 *
 * `$escalation` arrives pre-filled from the site settings
 * meilisearch.rag.fallback.* and translated into `$language`; `$show` carries
 * the default decision of meilisearch.rag.fallback.show. Listeners may
 * replace the card, add actions (a contact form in the visitor's language, a
 * booking link …) or flip `$show` in either direction.
 *
 * Hrefs may contain placeholders which the resolver substitutes after all
 * listeners ran, URL-encoded:
 *   {conversationId}  ID of the stored chat protocol (empty without one)
 *   {languageId}      site language id
 *   {language}        two-letter ISO code of the site language
 *   {question}        the visitor's current question
 * A listener that already knows the values (e.g. one building a URL through
 * the site router) can of course insert them itself.
 *
 * `$reason` explains why the default rule fired: the answer status
 * (`no_context`, `failed`, `disabled`, `clarify`), `uncited` for an answer
 * that cited no source, `answered` for a grounded answer, and `static` for the
 * always-visible card that is rendered before any question was asked.
 */
final class RagEscalationEvent
{
    public const REASON_UNCITED = 'uncited';
    public const REASON_ANSWERED = 'answered';
    public const REASON_STATIC = 'static';

    /**
     * @param list<string> $citedIds
     */
    public function __construct(
        public readonly Site $site,
        public readonly ?SiteLanguage $language,
        public readonly string $status,
        public readonly string $reason,
        public readonly array $citedIds,
        public readonly string $question,
        public readonly string $conversationId,
        public Escalation $escalation,
        public bool $show,
    ) {}
}
