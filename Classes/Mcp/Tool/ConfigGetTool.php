<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Mcp\Tool;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Mcp\McpClient;
use WapplerSystems\Meilisearch\Mcp\McpContext;
use WapplerSystems\Meilisearch\Mcp\McpToolException;
use WapplerSystems\Meilisearch\Mcp\McpToolInterface;

/**
 * Read-only view of what is in force for the chat of a site: the system
 * prompt and the other long texts, the settings that shape answers, and the
 * result of the last regression run.
 *
 * The site owner reviews ideas against the rules before sending them in and
 * found that new rules began to contradict older ones (LINEAR 18.11.2026);
 * without seeing the prompt and the settings that was guesswork. Settings are
 * the site settings under meilisearch.mcp.config.prefixes; keys that hold
 * credentials (api key, token, secret, password, dsn) are never returned.
 * The regression run is a JSON file a catalogue runner writes
 * (meilisearch.mcp.config.regressionFile, under var/), returned in pages.
 */
final class ConfigGetTool implements McpToolInterface
{
    private const SECRET = '/^(?:api_?key|\w*secret\w*|\w*password\w*|passwd|(?:access|auth|api|bearer|manager)?_?token|dsn|credentials?)$/i';

    /** Long texts kept in files: the prompt and the entries built from settings. */
    private const TEXTS = [
        'systemPrompt' => 'meilisearch.rag.systemPrompt',
        'factSheet' => 'meilisearch.rag.factSheet',
        'fixedFacts' => 'meilisearch.rag.fixedFacts',
    ];

    public function getName(): string
    {
        return 'config_get';
    }

    public function getDescription(): string
    {
        return 'Returns what is in force for the chat of this site, each part with the date of its last change. section "prompt": the system prompt and the fact sheet / fixed facts texts. section "settings": the settings that shape answers — fixed replies per language (fixedReplies), "nothing found" phrases, fixed answers on patterns, term and platform rules, answer-guard switches, search and model parameters (hits per source, semanticRatio, model, temperature, answer length). section "regression": the last run of the regression catalogue, per question the question, the expectation and the result (answer, sources, status, as expected yes/no/read) — paged with limit/offset, narrowed with onlyDeviations or ids. Default section: "summary" (dates and sizes of all parts).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'section' => ['type' => 'string', 'enum' => ['summary', 'prompt', 'settings', 'regression'], 'default' => 'summary'],
                'onlyDeviations' => ['type' => 'boolean', 'description' => 'regression: only questions not as expected.'],
                'ids' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 64], 'maxItems' => 100, 'description' => 'regression: only these case ids.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
            ],
            'additionalProperties' => false,
        ];
    }

    public function getScope(): string
    {
        return McpClient::SCOPE_CONFIG_READ;
    }

    public function isWriting(): bool
    {
        return false;
    }

    public function call(array $arguments, McpContext $context): array
    {
        $section = $arguments['section'] ?? 'summary';
        if (!in_array($section, ['summary', 'prompt', 'settings', 'regression'], true)) {
            throw new McpToolException('section must be summary, prompt, settings or regression.');
        }
        $site = $context->site;

        return match ($section) {
            'prompt' => $this->texts($site),
            'settings' => $this->settings($site),
            'regression' => $this->regression($site, $arguments),
            default => $this->summary($site),
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function summary(Site $site): array
    {
        $texts = [];
        foreach ($this->texts($site)['texts'] as $name => $text) {
            $texts[$name] = ['changed' => $text['changed'], 'characters' => mb_strlen($text['content'])];
        }
        $settings = $this->settings($site);
        $regression = $this->regressionData($site);

        return [
            'prompt' => $texts,
            'settings' => ['changed' => $settings['changed'], 'count' => count($settings['settings'])],
            'regression' => $regression === null ? null : [
                'run' => $regression['lauf'] ?? '',
                'summary' => $regression['zusammenfassung'] ?? [],
                'questions' => count($regression['fragen'] ?? []),
            ],
        ];
    }

    /**
     * @return array{texts: array<string, array{setting:string,file:string,changed:string,content:string}>}
     */
    private function texts(Site $site): array
    {
        $texts = [];
        foreach (self::TEXTS as $name => $key) {
            $value = (string)$site->getSettings()->get($key, '');
            if ($value === '') {
                continue;
            }
            $file = $this->siteFile($site, $value);
            $texts[$name] = [
                'setting' => $key,
                'file' => $file !== null ? basename($file) : '',
                'changed' => $file !== null ? date('c', (int)filemtime($file)) : $this->settingsChanged($site),
                'content' => $file !== null ? (string)file_get_contents($file) : $value,
            ];
        }

        return ['texts' => $texts];
    }

    /**
     * @return array{changed:string, prefixes: list<string>, settings: array<string,mixed>}
     */
    private function settings(Site $site): array
    {
        $prefixes = array_values(array_filter(array_map(
            static fn ($p): string => trim((string)$p),
            (array)$site->getSettings()->get('meilisearch.mcp.config.prefixes', ['meilisearch.rag.', 'meilisearch.search.']),
        )));
        $values = [];
        foreach ($site->getSettings()->getAllFlat() as $key => $value) {
            $last = substr((string)$key, (int)strrpos((string)$key, '.') + 1);
            if (preg_match(self::SECRET, $last) === 1 || in_array((string)$key, self::TEXTS, true)) {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with((string)$key, $prefix)) {
                    $values[(string)$key] = $value;
                    break;
                }
            }
        }
        ksort($values);

        return ['changed' => $this->settingsChanged($site), 'prefixes' => $prefixes, 'settings' => $this->groupLists($values)];
    }

    /**
     * getAllFlat() flattens lists to "key.0", "key.1" …; a list reads better whole.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function groupLists(array $values): array
    {
        $grouped = [];
        foreach ($values as $key => $value) {
            if (preg_match('/^(.+)\.(\d+)$/', $key, $m) === 1) {
                $grouped[$m[1]][(int)$m[2]] = $value;
                continue;
            }
            $grouped[$key] = $value;
        }
        foreach ($grouped as $key => $value) {
            if (is_array($value)) {
                ksort($value);
                $grouped[$key] = array_values($value);
            }
        }

        return $grouped;
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function regression(Site $site, array $arguments): array
    {
        $data = $this->regressionData($site);
        if ($data === null) {
            throw new McpToolException('No regression run is published for this site (meilisearch.mcp.config.regressionFile).');
        }
        $rows = array_values(array_filter((array)($data['fragen'] ?? []), 'is_array'));
        if (($arguments['onlyDeviations'] ?? false) === true) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => ($r['wie_erwartet'] ?? '') === 'nein'));
        }
        if (is_array($arguments['ids'] ?? null) && $arguments['ids'] !== []) {
            $ids = array_flip(array_map('strval', $arguments['ids']));
            $rows = array_values(array_filter($rows, static fn (array $r): bool => isset($ids[(string)($r['id'] ?? '')])));
        }
        $limit = max(1, min(200, (int)($arguments['limit'] ?? 50)));
        $offset = max(0, (int)($arguments['offset'] ?? 0));

        return [
            'run' => $data['lauf'] ?? '',
            'summary' => $data['zusammenfassung'] ?? [],
            'total' => count($rows),
            'offset' => $offset,
            'questions' => array_slice($rows, $offset, $limit),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function regressionData(Site $site): ?array
    {
        $relative = trim((string)$site->getSettings()->get('meilisearch.mcp.config.regressionFile', ''));
        if ($relative === '') {
            return null;
        }
        $varPath = realpath(Environment::getVarPath());
        $file = realpath(Environment::getVarPath() . '/' . ltrim($relative, '/'));
        if ($varPath === false || $file === false || !str_starts_with($file, $varPath . '/') || !is_file($file)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    private function settingsChanged(Site $site): string
    {
        $file = Environment::getConfigPath() . '/sites/' . $site->getIdentifier() . '/settings.yaml';

        return is_file($file) ? date('c', (int)filemtime($file)) : '';
    }

    private function siteFile(Site $site, string $value): ?string
    {
        if (!str_starts_with($value, 'file:')) {
            return null;
        }
        $siteDirectory = realpath(Environment::getConfigPath() . '/sites/' . $site->getIdentifier());
        $file = realpath($siteDirectory . '/' . trim(substr($value, 5)));

        return $siteDirectory !== false && $file !== false && str_starts_with($file, $siteDirectory . '/') && is_file($file) ? $file : null;
    }
}
