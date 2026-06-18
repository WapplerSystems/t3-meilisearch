<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Import\Importer;

use WapplerSystems\Meilisearch\Service\Import\KnowledgeResourceRepository;
use WapplerSystems\Meilisearch\Service\Import\KnowledgeResourceSourceImporter;
use WapplerSystems\Meilisearch\Service\Import\ImportResult;

/**
 * Reads a DITA-OT XHTML drop (one HTML file per topic + an index.html
 * TOC + an optional figures/ folder of illustrations). One language
 * directory per import run; multi-language sources re-run the importer
 * with a different langDir.
 *
 * The filename is the natural unique key, NOT the DITA `DC.identifier`
 * — DITA-OT happily emits multiple HTML stubs with the same DC.identifier
 * when a topic is included from several TOC branches (conref / reuse).
 *
 * In purge mode (default) the importer wipes all rows for the chosen
 * language plus their FAL refs before re-running. That keeps re-imports
 * idempotent and orphan-free without operators having to track which
 * topics disappeared between drops.
 */
final class DitaOtImporter implements KnowledgeResourceSourceImporter
{
    public function __construct(
        private readonly KnowledgeResourceRepository $repository,
    ) {}

    public function name(): string
    {
        return 'dita-ot';
    }

    public function label(): string
    {
        return 'DITA-OT XHTML drop';
    }

    public function description(): string
    {
        return 'A folder containing an index.html TOC and a <langDir>/topics/*.html tree (the standard DITA-OT XHTML output).';
    }

    public function describeFields(): array
    {
        return [
            ['name' => 'path', 'label' => 'Source path', 'type' => 'text', 'required' => true,
             'help' => 'Absolute or relative to project root. Must contain index.html and the language subdir.'],
            ['name' => 'langDir', 'label' => 'Language directory', 'type' => 'text', 'default' => 'de',
             'help' => 'Subdirectory under --path that contains topics/ and figures/.'],
            ['name' => 'language', 'label' => 'Target sys_language_uid', 'type' => 'language', 'default' => 0],
            ['name' => 'pid', 'label' => 'Storage pid', 'type' => 'text', 'default' => '0',
             'help' => 'Page id where the records live. 0 = site root.'],
            ['name' => 'purge', 'label' => 'Purge before importing', 'type' => 'checkbox', 'default' => true,
             'help' => 'Recommended — wipes existing rows for the chosen language so re-runs are idempotent.'],
            ['name' => 'limit', 'label' => 'Limit', 'type' => 'text', 'default' => '0',
             'help' => 'Only import the first N topics. 0 = no limit.'],
            ['name' => 'targetFolder', 'label' => 'Target media folder', 'type' => 'folder',
             'help' => 'Where the topic-specific media subfolders are created in fileadmin. Empty = site default (meilisearch.knowledgeResource.fileadminFolder).'],
        ];
    }

    public function import(array $config, ?callable $onProgress = null): ImportResult
    {
        $rootPath = $this->repository->resolvePath((string)($config['path'] ?? ''));
        $langDir = trim((string)($config['langDir'] ?? 'de'));
        $languageId = (int)($config['language'] ?? 0);
        $pid = (int)($config['pid'] ?? 0);
        $limit = max(0, (int)($config['limit'] ?? 0));
        $purge = (bool)($config['purge'] ?? true);
        $targetRoot = trim((string)($config['targetFolder'] ?? ''));
        $targetRootOrNull = $targetRoot !== '' ? $targetRoot : null;

        if ($rootPath === '') {
            throw new \RuntimeException('path is required');
        }
        $tocFile = $rootPath . '/index.html';
        $topicsDir = $rootPath . '/' . $langDir . '/topics';
        if (!is_file($tocFile)) {
            throw new \RuntimeException('TOC file not found: ' . $tocFile);
        }
        if (!is_dir($topicsDir)) {
            throw new \RuntimeException('Topics directory not found: ' . $topicsDir);
        }

        $parentMap = $this->parseTocParents($tocFile, $langDir);

        if ($purge) {
            $this->repository->purgeLanguage($languageId);
        }

        $topicFiles = glob($topicsDir . '/*.html') ?: [];
        sort($topicFiles);
        if ($limit > 0) {
            $topicFiles = array_slice($topicFiles, 0, $limit);
        }
        $total = count($topicFiles);

        $imported = 0;
        $mediaCopied = 0;
        $skipped = 0;
        $index = 0;

        foreach ($topicFiles as $topicFile) {
            $index++;
            $row = $this->parseTopic($topicFile);
            if ($row === null) {
                $skipped++;
                if ($onProgress !== null) {
                    $onProgress($index, $total, '');
                }
                continue;
            }
            $mediaSourceAbs = $row['_mediaSourceAbs'] ?? null;
            unset($row['_mediaSourceAbs']);

            $row['sys_language_uid'] = $languageId;
            $row['pid'] = $pid;
            $row['parent_identifier'] = $parentMap[$row['identifier']] ?? '';
            $row['source_path'] = $this->relativeSourcePath($topicFile, $rootPath);
            $row['media'] = 0;

            $uid = $this->repository->insertKnowledgeResource($row);
            $imported++;

            if ($mediaSourceAbs !== null && is_file($mediaSourceAbs)) {
                $falFile = $this->repository->addFileFromPath($mediaSourceAbs, $row['identifier'], $targetRootOrNull);
                $this->repository->attachMedia($falFile, $uid, $languageId, $pid);
                $mediaCopied++;
            }
            if ($onProgress !== null) {
                $onProgress($index, $total, (string)$row['identifier']);
            }
        }

        return new ImportResult(
            imported: $imported,
            skipped: $skipped,
            mediaCopied: $mediaCopied,
            message: sprintf('DITA-OT import from %s (language %d)', $rootPath, $languageId),
        );
    }

    /**
     * @return array<string, string>
     */
    private function parseTocParents(string $tocFile, string $langDir): array
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML(file_get_contents($tocFile) ?: '', LIBXML_NOERROR);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);
        $map = [];
        $nodes = $xp->query('//li[contains(@class, "topicref")]/a[starts-with(@href, "' . $langDir . '/topics/")]');
        if (!$nodes instanceof \DOMNodeList) {
            return $map;
        }
        foreach ($nodes as $a) {
            /** @var \DOMElement $a */
            $childId = $this->identifierFromHref($a->getAttribute('href'));
            if ($childId === '') {
                continue;
            }
            $ancestor = $xp->query('ancestor::li[contains(@class, "topicref")]/a[starts-with(@href, "' . $langDir . '/topics/")][1]', $a)->item(0);
            if ($ancestor instanceof \DOMElement) {
                $parentId = $this->identifierFromHref($ancestor->getAttribute('href'));
                if ($parentId !== '' && $parentId !== $childId) {
                    $map[$childId] = $parentId;
                }
            }
        }
        return $map;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseTopic(string $topicFile): ?array
    {
        $content = file_get_contents($topicFile);
        if ($content === false || $content === '') {
            return null;
        }
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML($content, LIBXML_NOERROR);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);

        $identifier = pathinfo($topicFile, PATHINFO_FILENAME);
        $title = $this->metaContent($xp, 'DC.title');
        if ($title === '') {
            $h1 = $xp->query('//body//h1[contains(@class, "topictitle")]')->item(0);
            $title = $h1 instanceof \DOMElement ? trim($h1->textContent) : '';
        }
        $abstract = $this->metaContent($xp, 'abstract') ?: $this->metaContent($xp, 'description');
        $resourceType = $this->metaContent($xp, 'DC.type') ?: 'concept';
        $body = $this->extractBody($xp);
        if ($title === '' && trim($body) === '') {
            return null;
        }
        $mediaPath = $this->firstMediaPath($xp, dirname($topicFile));
        return [
            'identifier' => substr($identifier, 0, 190),
            'title' => substr($title, 0, 512),
            'abstract' => $abstract,
            'body' => $body,
            'resource_type' => $resourceType,
            '_mediaSourceAbs' => $mediaPath,
        ];
    }

    private function metaContent(\DOMXPath $xp, string $name): string
    {
        $node = $xp->query(sprintf('//head/meta[@name=%s]', $this->quoteXpath($name)))->item(0);
        if (!$node instanceof \DOMElement) {
            return '';
        }
        return trim($node->getAttribute('content'));
    }

    private function extractBody(\DOMXPath $xp): string
    {
        $body = $xp->query('//body')->item(0);
        if (!$body instanceof \DOMElement) {
            return '';
        }
        foreach (['.//script', './/style', './/nav', './/header', './/footer'] as $strip) {
            foreach (iterator_to_array($xp->query($strip, $body)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        $html = $body->ownerDocument->saveHTML($body);
        $padded = preg_replace('#</(p|div|section|li|h[1-6]|td|tr)>#u', "$0\n", (string)$html) ?? (string)$html;
        $text = strip_tags($padded);
        $text = (string)preg_replace('/\h+/u', ' ', $text);
        $text = (string)preg_replace('/\n{3,}/u', "\n\n", $text);
        return trim($text);
    }

    private function firstMediaPath(\DOMXPath $xp, string $topicDir): ?string
    {
        $candidates = $xp->query('//body//img/@src | //body//source/@src | //body//video/@src');
        if (!$candidates instanceof \DOMNodeList) {
            return null;
        }
        foreach ($candidates as $attr) {
            $src = trim($attr->nodeValue);
            if ($src === '' || str_starts_with($src, 'http://') || str_starts_with($src, 'https://') || str_starts_with($src, '//')) {
                continue;
            }
            $abs = realpath($topicDir . '/' . $src);
            if ($abs !== false && is_file($abs)) {
                return $abs;
            }
        }
        return null;
    }

    private function identifierFromHref(string $href): string
    {
        return preg_replace('/\.html?$/i', '', basename($href)) ?? '';
    }

    private function quoteXpath(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }
        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }
        return "concat('" . str_replace("'", "',\"'\",'", $value) . "')";
    }

    private function relativeSourcePath(string $topicFile, string $rootPath): string
    {
        if (str_starts_with($topicFile, $rootPath . '/')) {
            return ltrim(substr($topicFile, strlen($rootPath)), '/');
        }
        return basename($topicFile);
    }
}