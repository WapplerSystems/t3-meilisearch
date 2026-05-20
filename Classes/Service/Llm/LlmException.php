<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Llm;

/**
 * Thrown when an LLM provider rejects a request or returns a malformed
 * response. Caller (RagService) catches and degrades gracefully — the user
 * gets a "couldn't answer" message instead of a 500.
 */
final class LlmException extends \RuntimeException {}
