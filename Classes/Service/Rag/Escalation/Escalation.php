<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag\Escalation;

/**
 * The "ask a human" card under a chat answer: a heading, an explanatory
 * sentence and the ways to reach someone.
 *
 * Immutable on purpose. Listeners of {@see \WapplerSystems\Meilisearch\Event\RagEscalationEvent}
 * build a modified copy with the with*() methods and hand it back, so the
 * default the resolver started from can never be changed behind its back.
 */
final class Escalation
{
    /**
     * @param list<EscalationAction> $actions
     */
    public function __construct(
        public readonly string $heading = '',
        public readonly string $text = '',
        public readonly array $actions = [],
    ) {}

    public function withHeading(string $heading): self
    {
        return new self($heading, $this->text, $this->actions);
    }

    public function withText(string $text): self
    {
        return new self($this->heading, $text, $this->actions);
    }

    /**
     * @param list<EscalationAction> $actions
     */
    public function withActions(array $actions): self
    {
        return new self($this->heading, $this->text, array_values($actions));
    }

    public function withAddedAction(EscalationAction $action, bool $prepend = false): self
    {
        $actions = $this->actions;
        $prepend ? array_unshift($actions, $action) : $actions[] = $action;

        return new self($this->heading, $this->text, $actions);
    }

    /**
     * A card without a single way to reach someone explains a dead end and
     * offers no exit — it is not rendered at all.
     */
    public function isEmpty(): bool
    {
        return $this->actions === [];
    }

    /**
     * Shape shared by the Fluid partial and the `fallback` stream frame, so
     * both render paths draw from exactly the same data.
     *
     * @return array{heading:string,text:string,actions:list<array{type:string,label:string,href:string,value:string,newWindow:bool}>}
     */
    public function toArray(): array
    {
        return [
            'heading' => $this->heading,
            'text' => $this->text,
            'actions' => array_map(static fn(EscalationAction $a): array => $a->toArray(), $this->actions),
        ];
    }
}
