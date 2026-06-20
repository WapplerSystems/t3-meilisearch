<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Resolves the per-request "what is this visitor allowed to see" filter
 * for Meilisearch.
 *
 * Strategy: every indexed doc carries an `accessGroups` int[] (empty =
 * public; positive = fe_group id; -2 = any logged-in user; -1 = only
 * anonymous). At search time we read the visitor's effective groupIds
 * from the TYPO3 Context (`frontend.user`.groupIds — same source TYPO3
 * uses for page access checking) and translate it into the Meilisearch
 * filter expression
 *
 *   (accessGroups IS EMPTY OR accessGroups IN [<groupIds>])
 *
 * Docs without restrictions are always visible; restricted docs surface
 * only when the visitor carries at least one matching group id.
 *
 * Opt-in via `meilisearch.accessControl.enabled` (default true). Turning
 * it off makes the search index visitor-blind — useful for environments
 * where editors want the BE preview to show everything regardless of
 * the FE user.
 */
final class AccessControlFilter
{
    public function __construct(
        private readonly Context $context,
    ) {}

    /**
     * Build the Meilisearch filter expression for the current request, or
     * `null` when access control is disabled / no visitor context is
     * resolvable (BE/CLI calls fall through to "see everything" — same
     * semantics as TYPO3 has historically, since BE users aren't
     * fe_group-restricted).
     */
    public function buildFilter(Site $site, ?ServerRequestInterface $request = null): ?string
    {
        if (!$this->isEnabled($site)) {
            return null;
        }
        $groupIds = $this->resolveGroupIds($request);
        if ($groupIds === null) {
            // No FE-user context resolvable. Two cases:
            //   - Request given but no `frontend.user` attribute → anonymous
            //     visitor; restrict to public docs only.
            //   - No request at all (CLI / scheduler / BE module) → operator
            //     context, no FE filtering; return null so the caller skips
            //     the filter (mirrors how TYPO3 BE preview ignores fe_group).
            if ($request === null) {
                return null;
            }
            $groupIds = [-1];
        }
        $list = implode(',', array_map(static fn (int $g): string => (string)$g, $groupIds));
        return sprintf('(accessGroups IS EMPTY OR accessGroups IN [%s])', $list);
    }

    /**
     * Merge the access filter into an existing filters array (the
     * `filters` option SearchService::search consumes — keys are field
     * names, values are scalars or arrays of allowed values). Access
     * control is OR-conjunctive across groups but AND-conjunctive with
     * the rest, so we store it under a reserved key the SearchService
     * treats as a literal filter expression instead of an `IN` list.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function applyTo(array $filters, Site $site, ?ServerRequestInterface $request = null): array
    {
        $expression = $this->buildFilter($site, $request);
        if ($expression === null) {
            return $filters;
        }
        // Reserved key — SearchService::buildMeilisearchFilter passes
        // values stored under `__rawFilters` through as raw expressions
        // (AND-conjoined with the rest). Multiple raw filters allowed.
        $raw = $filters['__rawFilters'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }
        $raw[] = $expression;
        $filters['__rawFilters'] = $raw;
        return $filters;
    }

    private function isEnabled(Site $site): bool
    {
        return (bool)$site->getSettings()->get('meilisearch.accessControl.enabled', true);
    }

    /**
     * Read the FE visitor's groupIds from the request attribute or the
     * Context aspect. Both paths agree on TYPO3's convention (-1 =
     * anonymous; -2 = any logged-in user; positive = membership).
     *
     * Returns null when no FE-user context is available (BE/CLI),
     * letting the caller decide between "treat as anonymous" (strict)
     * and "skip filter" (permissive).
     *
     * @return list<int>|null
     */
    private function resolveGroupIds(?ServerRequestInterface $request): ?array
    {
        if ($request !== null) {
            $user = $request->getAttribute('frontend.user');
            if (is_object($user) && property_exists($user, 'userGroups')) {
                $ids = array_map('intval', array_keys((array)$user->userGroups));
                if ($ids !== []) {
                    return $this->normalise($ids, isAnonymous: !$this->isLoggedIn($user));
                }
            }
        }
        try {
            if ($this->context->hasAspect('frontend.user')) {
                $ids = (array)$this->context->getPropertyFromAspect('frontend.user', 'groupIds', []);
                if ($ids !== []) {
                    $isAnonymous = (int)$this->context->getPropertyFromAspect('frontend.user', 'id', 0) === 0;
                    return $this->normalise(array_map('intval', $ids), $isAnonymous);
                }
            }
        } catch (\Throwable) {
            // Aspect not registered (CLI without booted FE) — fall through.
        }
        return null;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function normalise(array $ids, bool $isAnonymous): array
    {
        $ids = array_values(array_unique($ids));
        // Anonymous visitor always carries -1, logged-in users always -2.
        // Lochmueller/EXT:index follows the same convention when writing
        // page accessGroups; mirroring it here keeps the symmetry.
        $pseudo = $isAnonymous ? -1 : -2;
        if (!in_array($pseudo, $ids, true)) {
            $ids[] = $pseudo;
        }
        return $ids;
    }

    private function isLoggedIn(object $user): bool
    {
        if (property_exists($user, 'user') && is_array($user->user) && isset($user->user['uid'])) {
            return (int)$user->user['uid'] > 0;
        }
        return false;
    }
}
