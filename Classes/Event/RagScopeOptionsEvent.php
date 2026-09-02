<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Event;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Dispatched after an answer is built, to collect the scopes the same question
 * could be asked in again.
 *
 * A documentation set that carries one topic per product, edition or release
 * answers a general question from whichever variant retrieval ranked highest.
 * The answer says which one it used, but the reader who wanted the other one
 * has to retype the question with the product appended. Listeners add those
 * alternatives here and they appear as buttons under the answer, each asking
 * the original question again with the choice attached.
 *
 * This is deliberately a refinement, not a clarifying question up front:
 * asking back before answering was measured on a bilingual, three-release
 * knowledge base and turned four out of eight questions into a round trip —
 * one of them for a question that had no product dimension at all. Answering
 * and offering the switch afterwards costs the reader nothing when the first
 * answer was already right.
 *
 * What counts as a scope is site-specific, so this extension derives nothing
 * on its own: no listener means no buttons and unchanged behaviour. Sources
 * are read-only; a listener chooses wording, it does not reshape retrieval.
 */
final class RagScopeOptionsEvent
{
    /**
     * @var list<array{label:string,choice:string}>
     */
    private array $options = [];

    /**
     * @param list<array<string,mixed>> $sources documents the answer was built from
     */
    public function __construct(
        private readonly Site $site,
        private readonly string $question,
        private readonly array $sources,
    ) {}

    public function getSite(): Site
    {
        return $this->site;
    }

    /**
     * The question as asked, so a listener can tell whether the reader already
     * named the scope and stay silent if they did.
     */
    public function getQuestion(): string
    {
        return $this->question;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getSources(): array
    {
        return $this->sources;
    }

    /**
     * @param string $label  what the button reads, e.g. "AutoCAD"
     * @param string $choice what gets appended to the question when clicked;
     *                       defaults to the label, which is usually right
     */
    public function addOption(string $label, string $choice = ''): void
    {
        $label = trim($label);
        $choice = trim($choice) !== '' ? trim($choice) : $label;
        if ($label === '') {
            return;
        }
        foreach ($this->options as $option) {
            if (mb_strtolower($option['label']) === mb_strtolower($label)) {
                return;
            }
        }
        $this->options[] = ['label' => $label, 'choice' => $choice];
    }

    /**
     * @return list<array{label:string,choice:string}>
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
