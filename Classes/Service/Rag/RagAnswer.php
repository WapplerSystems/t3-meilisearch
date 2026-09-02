<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

/**
 * Result of a single RAG call. The template renders `$answer` as the chat
 * bubble; `$sources` provides the (potentially clickable) citation list so
 * users can verify what the LLM grounded its response on.
 */
final class RagAnswer
{
    /**
     * @param list<array<string,mixed>> $sources   Search hits sent to the LLM as context.
     * @param list<string>              $citedIds  IDs the LLM actually referenced in its response.
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $sources,
        public readonly array $citedIds,
        public readonly string $status,
        public readonly ?string $error = null,
        /** @var list<array{type:string,label:string,value:string}> */
        public readonly array $suggestions = [],
    ) {}

    /**
     * Immutable copy with action suggestions attached (followup / refine /
     * recommend). Generated after the answer so the model can base them on
     * the produced text + sources; never affects the answer itself.
     *
     * @param list<array{type:string,label:string,value:string}> $suggestions
     */
    public function withSuggestions(array $suggestions): self
    {
        return new self(
            $this->answer,
            $this->sources,
            $this->citedIds,
            $this->status,
            $this->error,
            $suggestions,
        );
    }

    public static function failed(string $error): self
    {
        return new self('', [], [], 'failed', $error);
    }

    public static function disabled(): self
    {
        return new self('', [], [], 'disabled', null);
    }

    public static function noContext(): self
    {
        return new self('', [], [], 'no_context', null);
    }

    /**
     * The question was too ambiguous / underspecified to answer well, so the
     * assistant asks one clarifying question back instead of guessing. The
     * clarifying question is carried in {@see $answer} so the template renders
     * it in the answer bubble; there are no sources or citations.
     */
    /**
     * The choices ride along as suggestions of type `clarify`: the suggestion
     * partial is rendered outside the status switch and already turns
     * {type,label,value} rows into re-ask links, so the visitor answers the
     * clarifying question with one click instead of retyping a product name.
     *
     * @param list<array{label:string,value:string}> $options
     */
    public static function clarification(string $question, array $options = []): self
    {
        $suggestions = [];
        foreach ($options as $option) {
            $suggestions[] = [
                'type' => 'clarify',
                'label' => (string)($option['label'] ?? ''),
                'value' => (string)($option['value'] ?? ''),
            ];
        }

        return new self(trim($question), [], [], 'clarify', null, $suggestions);
    }

    /**
     * Sources the FE template may show as a clickable citation list.
     * Strips hits of type 'knowledge_resource' — those are internal
     * RAG-grounding corpus (DITA topics imported via the
     * KnowledgeResource importer) which are deliberately hidden from
     * the public sources panel even though the LLM is told to ground
     * its answer in them. Used by `Rag/Ask.html`; the LLM-context path
     * keeps reading `$sources` directly so the full hit set still
     * grounds the answer.
     *
     * @return list<array<string,mixed>>
     */
    public function getPublicSources(): array
    {
        return array_values(array_filter(
            $this->sources,
            static fn(array $hit): bool => (string)($hit['type'] ?? '') !== 'knowledge_resource',
        ));
    }

    /**
     * HTML-rendered version of {@see $answer}: citation markers become
     * numbered references with a legend, **bold** becomes <strong>.
     *
     * The rendering itself lives in {@see CitationRenderer} because a stored
     * conversation turn needs exactly the same output — keeping a second copy
     * here is what made the identical citation-format defect need fixing
     * twice.
     */
    public function getAnswerHtml(): string
    {
        return CitationRenderer::render($this->answer, $this->sources);
    }
}
