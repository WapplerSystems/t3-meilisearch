<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Import;

/**
 * Contract for anything that pulls knowledge documents into
 * tx_wsmeilisearch_knowledge_resource + the linked FAL files. Each implementation
 * understands one source format (DITA-OT XHTML drop, single file
 * upload, ZIP bundle, URL crawl, Confluence export, …).
 *
 * Implementations are picked up via DI auto-tagging (`_instanceof` in
 * Services.yaml → `ws_meilisearch.source_importer`) and looked up by
 * {@see name()} through {@see SourceImporterRegistry}.
 *
 * Lifecycle:
 *   1. Operator (BE form / CLI) selects an importer by name.
 *   2. {@see describeFields()} drives form rendering / CLI option list.
 *   3. Operator fills in values → array keyed by field `name`.
 *   4. {@see import()} runs, returns ImportResult.
 *
 * The implementation does its own persistence via the shared
 * KnowledgeResourceRepository (DI-injected). The interface stays storage-agnostic
 * so a future importer could write to a different table without
 * touching this contract.
 */
interface KnowledgeResourceSourceImporter
{
    /** Machine slug: 'dita-ot', 'single-file', 'zip-bundle', … */
    public function name(): string;

    /** Human-readable label for BE dropdown. */
    public function label(): string;

    /** One-line description of what kind of source this accepts. */
    public function description(): string;

    /**
     * Schema for the form / CLI arg parser. Each entry:
     *   [
     *     'name'     => 'path',                   // required, used as $config key
     *     'label'    => 'Source path',            // shown to the operator
     *     'type'     => 'text'|'file'|'select'|'checkbox'|'textarea'|'language'|'folder',
     *     'required' => true,                     // default false
     *     'default'  => '...',                    // optional pre-fill
     *     'options'  => ['de' => 'Deutsch', …],   // for type=select
     *     'help'     => 'Free-form hint…',        // optional
     *   ]
     *
     * Field types:
     *   - text:      <input type="text">
     *   - file:      <input type="file"> (single PSR-7 UploadedFileInterface)
     *   - select:    <select>; supply `options` map
     *   - checkbox:  <input type="checkbox"> (boolean)
     *   - textarea:  multi-line text
     *   - language:  <select> populated from the active site's languages
     *   - folder:    FAL combined identifier (e.g. "1:/helpdocs/"), rendered
     *                with TYPO3's folder picker on the BE side; the value is
     *                resolved to a Folder via KnowledgeResourceRepository::resolveFolder().
     *
     * @return list<array{name:string,label:string,type:string,required?:bool,default?:mixed,options?:array<string,string>,help?:string}>
     */
    public function describeFields(): array;

    /**
     * Run the import.
     *
     * @param array<string, mixed> $config validated field values, keyed by `name`
     * @param ?callable(int $current, int $total, string $marker): void $onProgress optional progress callback
     */
    public function import(array $config, ?callable $onProgress = null): ImportResult;
}