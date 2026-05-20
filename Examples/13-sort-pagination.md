# 13 — Sort + pagination in the FE search plugin

Pagination has always been in the data layer (the controller already
accepts `?page=`); this example wires the UI parts a site package
typically needs — a sort dropdown, prev/next links, and a "page X of Y"
label — and documents the SearchResult helpers that drive them.

## What's already there

`SearchService::search($site, $q, $options)` accepts:

| Option | Type | Notes |
|---|---|---|
| `page` | int | 1-based page number. |
| `perPage` | int | Hits per page. Controller defaults to the plugin's `settings.perPage` (FlexForm). |
| `sort` | `string` or `list<string>` | `"field:asc"`, `"field:desc"`, or a list of those for multi-sort. Empty / omitted = relevance ranking. |

It returns a `SearchResult` DTO that already exposes pagination helpers:

```php
$result->page              // current page, 1-based
$result->perPage           // hits per page
$result->totalHits         // total matching documents
$result->getTotalPages()   // ceil(totalHits / perPage); 0 when no hits
$result->getHasPreviousPage()
$result->getHasNextPage()
```

The `SearchController::resultsAction` accepts a `sort` GET parameter
and passes it through, and assigns the result + the current sort
value to the view so templates can keep the dropdown sticky across
submissions.

## Sortable fields out of the box

Sort works on fields the schema providers declare with `sortable: true`:

- `fileSize` — from `FileSchemaProvider` (the obvious "biggest file first" sort)
- `datetime` — from `NewsSchemaProvider` (used in News records)

A custom `SchemaProvider` can contribute more — see
[09](09-custom-schema-provider.md). When you do, also remember:

- Meilisearch's `sortableAttributes` list is set automatically by the
  SEAL adapter from your schema. The next `ws_meilisearch:reindex
  --rebuild` writes it out.
- Sorting on a non-sortable field returns a hard 400 from
  Meilisearch — `SearchService` lets it bubble up; the FE template
  should constrain the dropdown to known-sortable fields.

## Sort presets exposed by the controller

`SearchController::sortOptions()` returns a list of
`{value, label}` pairs for the default sort dropdown:

```php
[
    ['value' => '',                'label' => 'Relevance'],
    ['value' => 'datetime:desc',   'label' => 'Newest first'],
    ['value' => 'datetime:asc',    'label' => 'Oldest first'],
    ['value' => 'fileSize:desc',   'label' => 'Largest file first'],
    ['value' => 'fileSize:asc',    'label' => 'Smallest file first'],
]
```

Site packages can replace this list by overriding the controller,
extending it via a listener that mutates the view variables, or
just hard-coding the dropdown in the template.

## Template snippets

### Sort dropdown

```html
<select name="tx_wsmeilisearch_search[sort]" onchange="this.form.requestSubmit()">
    <f:for each="{sortOptions}" as="opt">
        <option value="{opt.value}"
            {f:if(condition: '{opt.value} == {sort}', then: 'selected="selected"')}>
            {opt.label}
        </option>
    </f:for>
</select>
```

`onchange="this.form.requestSubmit()"` follows the same auto-submit
pattern the facet checkboxes already use — no separate "Apply" button
needed. The form's `method="get"` puts the new sort into the URL so
the result is bookmarkable.

### Pagination

```html
<f:if condition="{result.totalPages} > 1">
    <nav class="pagination">
        <f:if condition="{result.hasPreviousPage}">
            <f:link.action
                action="results"
                arguments="{q: query, page: '{result.page - 1}', sort: sort, filters: filters, hybrid: hybrid}"
                noCacheHash="true">&laquo; Previous</f:link.action>
        </f:if>

        <span class="pagination__current">
            Page {result.page} of {result.totalPages}
        </span>

        <f:if condition="{result.hasNextPage}">
            <f:link.action
                action="results"
                arguments="{q: query, page: '{result.page + 1}', sort: sort, filters: filters, hybrid: hybrid}"
                noCacheHash="true">Next &raquo;</f:link.action>
        </f:if>
    </nav>
</f:if>
```

Numbered pages are a small extra step on top:

```html
<f:for each="{0:1, 1:2, 2:3, …}" as="pageNum">
    <f:link.action … arguments="{q: query, page: pageNum, …}">{pageNum}</f:link.action>
</f:for>
```

For real numbered pagination the controller would typically expose a
ranged page list (e.g. current ± 3 with first/last) — easy to add in
a custom controller or via an `AfterSearchEvent` listener that
augments the view variables.

## Programmatic usage

```php
// "Biggest file across all sites" report from a scheduler task
$top = $this->searchService->search($site, '', [
    'filters' => ['type' => 'file'],
    'sort' => 'fileSize:desc',
    'perPage' => 1,
]);
echo "Largest file: ", $top->hits[0]['title'] ?? '(none)';

// Multi-sort
$result = $this->searchService->search($site, '', [
    'sort' => ['datetime:desc', 'title:asc'],
]);
```

## Hybrid + sort

When `hybrid: true`, sort is forwarded through to Meilisearch's
`sort` parameter — same field set. Note that mixing semantic
ranking with an explicit sort means the explicit sort wins; the
semantic score becomes a tiebreaker. For "show me semantically
related hits ordered by date", that's exactly what you want.

## What sort does NOT change

- Facet counts (`result.facets`) — still reflect the full filtered
  set, not the current page.
- Total hits — same.
- Citation logic in RAG — RAG always uses the top-N hits by the
  configured ranking (`useHybrid`), so the FE plugin's sort
  selection doesn't affect what the LLM sees.
