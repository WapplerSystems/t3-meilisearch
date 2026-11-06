<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Event;

use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Service\Rag\Clarification;
use WapplerSystems\Meilisearch\Service\Rag\Conversation;

/**
 * Dispatched before the clarify step (meilisearch.rag.clarify.enabled) decides
 * whether to ask the visitor back instead of answering.
 *
 * The built-in rule only sees product names: retrieved titles from two
 * products mean "ask which one". Whether that is right depends on knowledge
 * the extension does not have — a topic that is identical in both products,
 * a product the visitor already named two turns ago. A listener sets
 * `$allowed = false` and the question is answered straight away; the
 * classification LLM call is then skipped as well.
 *
 * The other way round, a listener that knows the question has to be asked
 * back sets `$clarification` (Clarification::needed() with its buttons). It
 * is asked as given, without the classifier and whether or not
 * meilisearch.rag.clarify.enabled is on: a rule the site can state exactly
 * ("the AutoCAD and the Revit article differ, the visitor named neither")
 * should not depend on a model's judgement.
 */
final class RagClarificationEvent
{
    public ?Clarification $clarification = null;

    /**
     * @param list<array<string,mixed>> $hits the retrieved context documents
     * @param int|null $languageId the page language, for the wording of a clarification
     */
    public function __construct(
        public readonly Site $site,
        public readonly string $question,
        public readonly Conversation $conversation,
        public readonly array $hits,
        public bool $allowed = true,
        public readonly ?int $languageId = null,
    ) {}
}
