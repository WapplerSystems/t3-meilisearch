<?php

declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Domain\RecordSource;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Contract for "ask the displaying plugin what is reachable".
 *
 * A record (news, product, …) has no intrinsic frontend URL — the URL and
 * the visible corpus both depend on the plugin instance(s) that display the
 * record (their demand/where + their detail page). This interface lets the
 * indexer obtain, per site:
 *
 *   - the SCOPE: which record uids are actually reachable through any of the
 *     type's frontend plugins (storage folders, categories, archive, time —
 *     resolved by the plugin's own demand logic, never re-implemented). This
 *     is the safety net: records in folders no plugin shows, or filtered out,
 *     are never indexed and thus never leaked into search.
 *   - the URL: the speaking detail URL for a reachable record in a language.
 *
 * Implementations are tagged 'ws_meilisearch.plugin_record_source' and may be
 * native (an extension implements it for its own records) or adapters (we
 * implement it for a third-party plugin by reading its configuration).
 */
interface PluginRecordSourceInterface
{
    /**
     * Document type this source scopes, matching the SchemaProvider type
     * (e.g. 'news').
     */
    public function getType(): string;

    /**
     * False when the backing extension/plugin isn't available — callers skip
     * scoping entirely (and must then fall back to their previous behaviour).
     */
    public function isAvailable(): bool;

    /**
     * Set of reachable default-language record uids for the site, unioned
     * across every displaying plugin. Keyed by uid (value true) for O(1)
     * membership checks. Translations are reachable iff their parent is in
     * this set — the caller resolves that via l10n_parent.
     *
     * @return array<int, true>
     */
    public function collectReachableUids(Site $site): array;

    /**
     * Speaking detail URL for a record uid in the given language, or '' when
     * no detail page/route is resolvable (caller then indexes without a uri).
     */
    public function buildUri(Site $site, int $uid, int $languageId): string;
}