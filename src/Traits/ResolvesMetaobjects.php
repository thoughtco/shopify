<?php

namespace Thoughtco\Shopify\Traits;

use Illuminate\Support\Facades\Log;
use Statamic\Support\Arr;
use Thoughtco\Shopify\Clients\Graphql;
use Throwable;

/**
 * Resolves metaobject references in metafields (`metaobject_reference` and
 * `list.metaobject_reference`, which Shopify returns as bare GIDs) by fetching
 * the metaobjects in one batched `nodes` query, rather than inline in the
 * product query where the cost would multiply by every variant and metafield.
 *
 * The metaobjects are attached to each metafield as `reference` (single) or
 * `references.nodes` (list), the shape Shopify returns when they're queried
 * inline, so a parser sees the same data either way. One level of nested
 * metaobject is resolved; media references resolve to their URL.
 *
 * Requires ThrottlesShopifyRequests.
 */
trait ResolvesMetaobjects
{
    /**
     * How many metaobjects to fetch per query.
     */
    protected int $metaobjectsPerQuery = 25;

    /**
     * Resolve the metaobjects referenced by several lists of metafield edges
     * (e.g. a product's and each of its variants'), in as few queries as possible.
     *
     * @param  array<array-key, array>  $edgeLists  lists of metafield edges ([['node' => [...]], ...])
     * @return array<array-key, array> the same lists, with references attached
     */
    protected function resolveMetaobjectReferences(Graphql $graphql, array $edgeLists): array
    {
        if (! config('shopify.metaobjects.resolve', true)) {
            return $edgeLists;
        }

        $ids = collect($edgeLists)
            ->flatMap(fn ($edges) => collect($edges)->flatMap(fn ($edge) => $this->unresolvedMetaobjectIds($edge['node'] ?? [])))
            ->unique()
            ->values()
            ->all();

        if (! $ids || ! $metaobjects = $this->fetchMetaobjects($graphql, $ids)) {
            return $edgeLists;
        }

        return array_map(fn ($edges) => array_map(function ($edge) use ($metaobjects) {
            if (isset($edge['node'])) {
                $edge['node'] = $this->attachMetaobjects($edge['node'], $metaobjects);
            }

            return $edge;
        }, $edges), $edgeLists);
    }

    /**
     * Fetch metaobjects by ID, keyed by ID. A failure (e.g. the app is missing the
     * read_metaobjects scope) is logged and leaves the references as GIDs.
     */
    protected function fetchMetaobjects(Graphql $graphql, array $ids): array
    {
        $metaobjects = [];

        foreach (array_chunk($ids, $this->metaobjectsPerQuery) as $chunk) {
            try {
                $response = $this->queryWithThrottle($graphql, [
                    'query' => $this->metaobjectsQuery(),
                    'variables' => ['ids' => $chunk],
                ]);
            } catch (Throwable $e) {
                Log::warning('Shopify: could not fetch metaobjects: '.$e->getMessage());

                continue;
            }

            foreach (Arr::get($response->getDecodedBody(), 'data.nodes') ?? [] as $node) {
                if (is_array($node) && isset($node['id'], $node['fields'])) {
                    $metaobjects[$node['id']] = $node;
                }
            }
        }

        return $metaobjects;
    }

    /**
     * The query fetching metaobjects by ID.
     */
    protected function metaobjectsQuery(): string
    {
        $selection = $this->metaobjectSelection();

        return <<<GQL
            query (\$ids: [ID!]!) {
              nodes(ids: \$ids) {
                ... on Metaobject { {$selection} }
              }
            }
            GQL;
    }

    /**
     * The fields selected for a metaobject: its fields with one nested metaobject level and media URLs.
     */
    protected function metaobjectSelection(): string
    {
        $media = '... on MediaImage { id image { altText url } } ... on GenericFile { id url }';

        $nested = "... on Metaobject { id handle type fields { key type value reference { {$media} } references(first: 25) { nodes { {$media} } } } }";

        return "id handle type displayName fields { key type value reference { {$media} {$nested} } references(first: 25) { nodes { {$media} {$nested} } } }";
    }

    /**
     * The metaobject GIDs a metafield references that weren't already queried inline.
     */
    private function unresolvedMetaobjectIds(array $metafield): array
    {
        if (isset($metafield['reference']) || isset($metafield['references'])) {
            return [];
        }

        return $this->metaobjectIdsIn($metafield) ?? [];
    }

    /**
     * The metaobject GIDs in a metafield's value, or null when it isn't a metaobject reference.
     */
    private function metaobjectIdsIn(array $metafield): ?array
    {
        $type = $metafield['type'] ?? null;

        if ($type && ! in_array($type, ['metaobject_reference', 'list.metaobject_reference'])) {
            return null;
        }

        $value = $metafield['jsonValue'] ?? null;

        if ($value === null && is_string($metafield['value'] ?? null)) {
            $value = str_starts_with($metafield['value'], '[') ? json_decode($metafield['value'], true) : $metafield['value'];
        }

        $ids = array_filter(Arr::wrap($value), fn ($id) => is_string($id) && str_starts_with($id, 'gid://shopify/Metaobject/'));

        if (! $ids) {
            return null;
        }

        return array_values($ids);
    }

    private function attachMetaobjects(array $metafield, array $metaobjects): array
    {
        if (isset($metafield['reference']) || isset($metafield['references']) || ! $ids = $this->metaobjectIdsIn($metafield)) {
            return $metafield;
        }

        $isList = ($metafield['type'] ?? null) === 'list.metaobject_reference'
            || is_array($metafield['jsonValue'] ?? null)
            || str_starts_with((string) ($metafield['value'] ?? ''), '[');

        if (! $isList) {
            if (isset($metaobjects[$ids[0]])) {
                $metafield['reference'] = $metaobjects[$ids[0]];
            }

            return $metafield;
        }

        $nodes = collect($ids)->map(fn ($id) => $metaobjects[$id] ?? null)->filter()->values()->all();

        // none could be fetched: keep the GIDs, as before metaobjects were resolved
        if ($nodes) {
            $metafield['references'] = ['nodes' => $nodes];
        }

        return $metafield;
    }
}
