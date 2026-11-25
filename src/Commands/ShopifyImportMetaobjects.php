<?php

namespace Thoughtco\Shopify\Commands;

use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Support\Arr;
use Thoughtco\Shopify\Actions\ParseMetafields;
use Thoughtco\Shopify\Support\StoreConfig;
use Thoughtco\Shopify\Traits\ResolvesMetaobjects;
use Thoughtco\Shopify\Traits\ThrottlesShopifyRequests;

class ShopifyImportMetaobjects extends Command
{
    use ResolvesMetaobjects;
    use RunsInPlease;
    use ThrottlesShopifyRequests;

    protected $signature = 'shopify:import:metaobjects
        {type : The metaobject definition type, e.g. designer}
        {--collection= : The Statamic collection to import into (defaults to shopify.metaobjects.collection)}
        {--store= : The handle of a specific store to import from}';

    protected $description = 'Import all Shopify metaobjects of a definition type into a Statamic collection';

    public function handle()
    {
        $handle = $this->option('collection') ?: config('shopify.metaobjects.collection', 'metaobjects');

        if (! $collection = Collection::findByHandle($handle)) {
            $this->error("The [{$handle}] collection doesn't exist. Create it first, or pass --collection.");

            return self::FAILURE;
        }

        $graphql = StoreConfig::clientFor($this->option('store'));
        $parser = app(ParseMetafields::class);
        $type = $this->argument('type');
        $selection = $this->metaobjectSelection();

        $query = <<<GQL
            query (\$type: String!, \$first: Int!, \$cursor: String) {
              metaobjects(type: \$type, first: \$first, after: \$cursor) {
                nodes { {$selection} }
                pageInfo {
                  hasNextPage
                  endCursor
                }
              }
            }
            GQL;

        $cursor = null;
        $count = 0;

        do {
            $response = $this->queryWithThrottle($graphql, [
                'query' => $query,
                'variables' => ['type' => $type, 'first' => $this->metaobjectsPerQuery, 'cursor' => $cursor],
            ]);

            $page = Arr::get($response->getDecodedBody(), 'data.metaobjects', []);

            foreach ($page['nodes'] ?? [] as $metaobject) {
                $this->saveMetaobject($collection->handle(), $metaobject, $parser);
                $count++;
            }

            $cursor = Arr::get($page, 'pageInfo.endCursor');
        } while (Arr::get($page, 'pageInfo.hasNextPage') && $cursor);

        $this->info("Imported {$count} [{$type}] metaobjects into the [{$collection->handle()}] collection");

        return self::SUCCESS;
    }

    private function saveMetaobject(string $collection, array $metaobject, ParseMetafields $parser): void
    {
        $entry = Entry::query()
            ->where('collection', $collection)
            ->where('metaobject_id', $metaobject['id'])
            ->first();

        $entry ??= Entry::make()->collection($collection);

        $data = $parser->metaobject($metaobject);

        // `handle` is the slug; `type` is kept as metaobject_type so it can't clash with blueprint fields
        $data['metaobject_type'] = Arr::pull($data, 'type');
        unset($data['handle']);

        $entry->slug($metaobject['handle'])
            ->merge(array_merge(['title' => $metaobject['displayName'] ?? $metaobject['handle']], $data, [
                'metaobject_id' => $metaobject['id'],
            ]))
            ->save();
    }
}
