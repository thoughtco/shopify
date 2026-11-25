<?php

namespace Thoughtco\Shopify\Tests\Unit;

use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades;
use Thoughtco\Shopify\Actions\ParseMetafields;
use Thoughtco\Shopify\Clients\Graphql;
use Thoughtco\Shopify\Clients\HttpResponse;
use Thoughtco\Shopify\Jobs;
use Thoughtco\Shopify\Tests\TestCase;

class MetaobjectsTest extends TestCase
{
    #[Test]
    public function parses_a_metaobject_reference_into_its_fields()
    {
        $fields = (new ParseMetafields)->execute([
            [
                'key' => 'designer',
                'type' => 'metaobject_reference',
                'value' => 'gid://shopify/Metaobject/1',
                'reference' => $this->designer(),
            ],
        ], 'product');

        $this->assertSame([
            'designer' => [
                'name' => 'Ada',
                'photo' => 'https://cdn.shopify.com/ada.jpg',
                'studio' => [
                    'city' => 'London',
                    'logo' => 'https://cdn.shopify.com/logo.png',
                    'handle' => 'studio-ada',
                    'type' => 'studio',
                ],
                'awards' => ['Best in show'],
                'handle' => 'ada',
                'type' => 'designer',
            ],
        ], $fields);
    }

    #[Test]
    public function parses_a_list_of_metaobject_references_into_an_array_of_fields()
    {
        $fields = (new ParseMetafields)->execute([
            [
                'key' => 'features',
                'type' => 'list.metaobject_reference',
                'value' => '["gid://shopify/Metaobject/10","gid://shopify/Metaobject/11"]',
                'references' => ['nodes' => [
                    $this->feature(10, 'Waterproof'),
                    $this->feature(11, 'Lightweight'),
                ]],
            ],
        ], 'product');

        $this->assertSame([
            'features' => [
                ['label' => 'Waterproof', 'handle' => 'feature-10', 'type' => 'feature'],
                ['label' => 'Lightweight', 'handle' => 'feature-11', 'type' => 'feature'],
            ],
        ], $fields);
    }

    #[Test]
    public function leaves_other_metafields_and_unresolved_references_unchanged()
    {
        $fields = (new ParseMetafields)->execute([
            ['key' => 'sponsor', 'type' => 'single_line_text_field', 'value' => 'Shopify'],
            ['key' => 'designer', 'type' => 'metaobject_reference', 'value' => 'gid://shopify/Metaobject/1'],
            ['key' => 'image', 'type' => 'file_reference', 'value' => 'gid://shopify/MediaImage/1', 'reference' => ['image' => ['url' => 'https://cdn.shopify.com/a.jpg']]],
        ], 'product');

        $this->assertSame([
            'sponsor' => 'Shopify',
            'designer' => 'gid://shopify/Metaobject/1',
            'image' => 'gid://shopify/MediaImage/1',
        ], $fields);
    }

    #[Test]
    public function imports_metaobject_references_on_products_and_variants_in_one_query()
    {
        $this->makeProductCollections();

        $product = $this->product(
            productMetafields: [
                ['id' => 'gid://shopify/Metafield/1', 'key' => 'designer', 'type' => 'metaobject_reference', 'jsonValue' => 'gid://shopify/Metaobject/1', 'value' => 'gid://shopify/Metaobject/1'],
                ['id' => 'gid://shopify/Metafield/2', 'key' => 'sponsor', 'type' => 'single_line_text_field', 'jsonValue' => 'Shopify', 'value' => 'Shopify'],
            ],
            variantMetafields: [
                ['id' => 'gid://shopify/Metafield/3', 'key' => 'features', 'type' => 'list.metaobject_reference', 'jsonValue' => ['gid://shopify/Metaobject/10', 'gid://shopify/Metaobject/1'], 'value' => '["gid://shopify/Metaobject/10","gid://shopify/Metaobject/1"]'],
            ],
        );

        $this->mock(Graphql::class, function (MockInterface $mock) use ($product) {
            $mock->shouldReceive('query')
                ->withArgs(fn ($params) => str_contains($params['query'], 'product(id:'))
                ->andReturn(new HttpResponse(status: 200, body: json_encode($product)));

            $mock->shouldReceive('query')
                ->once()
                ->withArgs(fn ($params) => str_contains($params['query'], 'nodes(ids:')
                    && $params['variables']['ids'] === ['gid://shopify/Metaobject/1', 'gid://shopify/Metaobject/10'])
                ->andReturn(new HttpResponse(status: 200, body: json_encode(['data' => ['nodes' => [
                    $this->designer(),
                    $this->feature(10, 'Waterproof'),
                ]]])));
        });

        Jobs\ImportSingleProductJob::dispatch(1);

        $entry = Facades\Entry::whereCollection('products')->first();
        $this->assertSame('Ada', $entry->get('designer')['name']);
        $this->assertSame('London', $entry->get('designer')['studio']['city']);
        $this->assertSame('Shopify', $entry->get('sponsor'));

        $variant = Facades\Entry::whereCollection('variants')->first();
        $this->assertSame(['feature', 'designer'], array_column($variant->get('features'), 'type'));
    }

    #[Test]
    public function keeps_gids_when_metaobjects_cannot_be_fetched()
    {
        $this->makeProductCollections();

        $product = $this->product(productMetafields: [
            ['id' => 'gid://shopify/Metafield/1', 'key' => 'designer', 'type' => 'metaobject_reference', 'jsonValue' => 'gid://shopify/Metaobject/1', 'value' => 'gid://shopify/Metaobject/1'],
        ]);

        $this->mock(Graphql::class, function (MockInterface $mock) use ($product) {
            $mock->shouldReceive('query')
                ->withArgs(fn ($params) => str_contains($params['query'], 'product(id:'))
                ->andReturn(new HttpResponse(status: 200, body: json_encode($product)));

            // e.g. the app doesn't have the read_metaobjects scope
            $mock->shouldReceive('query')
                ->withArgs(fn ($params) => str_contains($params['query'], 'nodes(ids:'))
                ->andReturn(new HttpResponse(status: 200, body: json_encode(['errors' => [['message' => 'Access denied for nodes field.']]])));
        });

        Jobs\ImportSingleProductJob::dispatch(1);

        $entry = Facades\Entry::whereCollection('products')->first();
        $this->assertSame('gid://shopify/Metaobject/1', $entry->get('designer'));
    }

    #[Test]
    public function does_not_fetch_metaobjects_when_disabled()
    {
        config()->set('shopify.metaobjects.resolve', false);

        $this->makeProductCollections();

        $product = $this->product(productMetafields: [
            ['id' => 'gid://shopify/Metafield/1', 'key' => 'designer', 'type' => 'metaobject_reference', 'jsonValue' => 'gid://shopify/Metaobject/1', 'value' => 'gid://shopify/Metaobject/1'],
        ]);

        $this->mock(Graphql::class, function (MockInterface $mock) use ($product) {
            $mock->shouldReceive('query')
                ->withArgs(fn ($params) => str_contains($params['query'], 'product(id:'))
                ->andReturn(new HttpResponse(status: 200, body: json_encode($product)));

            $mock->shouldReceive('query')
                ->withArgs(fn ($params) => str_contains($params['query'], 'nodes(ids:'))
                ->never();
        });

        Jobs\ImportSingleProductJob::dispatch(1);

        $this->assertSame('gid://shopify/Metaobject/1', Facades\Entry::whereCollection('products')->first()->get('designer'));
    }

    #[Test]
    public function imports_metaobject_references_on_collections()
    {
        Facades\Taxonomy::make()->handle('collections')->save();

        $this->mock(Graphql::class, function (MockInterface $mock) {
            $mock->shouldReceive('query')
                ->withArgs(fn ($params) => str_contains($params['query'], 'collection(id:'))
                ->andReturn(new HttpResponse(status: 200, body: json_encode(['data' => ['collection' => [
                    'descriptionHtml' => '',
                    'handle' => 'summer',
                    'id' => 'gid://shopify/Collection/5',
                    'image' => null,
                    'metafields' => ['edges' => [
                        ['node' => ['id' => 'gid://shopify/Metafield/1', 'key' => 'features', 'type' => 'list.metaobject_reference', 'jsonValue' => ['gid://shopify/Metaobject/10'], 'value' => '["gid://shopify/Metaobject/10"]']],
                    ]],
                    'title' => 'Summer',
                ]]])));

            $mock->shouldReceive('query')
                ->withArgs(fn ($params) => str_contains($params['query'], 'nodes(ids:'))
                ->andReturn(new HttpResponse(status: 200, body: json_encode(['data' => ['nodes' => [$this->feature(10, 'Waterproof')]]])));
        });

        Jobs\ImportCollectionJob::dispatch(5);

        $term = Facades\Term::query()->where('taxonomy', 'collections')->first();
        $this->assertSame([['label' => 'Waterproof', 'handle' => 'feature-10', 'type' => 'feature']], $term->get('features'));
    }

    #[Test]
    public function imports_all_metaobjects_of_a_type_into_its_mapped_collection()
    {
        config()->set('shopify.metaobjects.collections', ['designer' => 'designers']);

        Facades\Collection::make('designers')->save();

        Facades\Entry::make()->collection('designers')->slug('old-handle')->data(['metaobject_id' => 'gid://shopify/Metaobject/1', 'title' => 'Old'])->save();

        $this->mock(Graphql::class, function (MockInterface $mock) {
            $mock->shouldReceive('query')
                ->withArgs(fn ($params) => str_contains($params['query'], 'metaobjects(type:') && $params['variables']['cursor'] === null && $params['variables']['type'] === 'designer')
                ->andReturn(new HttpResponse(status: 200, body: json_encode(['data' => ['metaobjects' => [
                    'nodes' => [$this->designer()],
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'abc'],
                ]]])));

            $mock->shouldReceive('query')
                ->withArgs(fn ($params) => str_contains($params['query'], 'metaobjects(type:') && $params['variables']['cursor'] === 'abc')
                ->andReturn(new HttpResponse(status: 200, body: json_encode(['data' => ['metaobjects' => [
                    'nodes' => [array_merge($this->designer(), ['id' => 'gid://shopify/Metaobject/2', 'handle' => 'grace', 'displayName' => 'Grace'])],
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                ]]])));
        });

        $this->artisan('shopify:import:metaobjects', ['type' => 'designer'])->assertSuccessful();

        $entries = Facades\Entry::whereCollection('designers')->sortBy('slug')->values();
        $this->assertCount(2, $entries);

        $ada = $entries[0];
        $this->assertSame('ada', $ada->slug());
        $this->assertSame('Ada', $ada->get('title'));
        $this->assertSame('Ada', $ada->get('name'));
        $this->assertSame('designer', $ada->get('metaobject_type'));
        $this->assertSame('gid://shopify/Metaobject/1', $ada->get('metaobject_id'));
        $this->assertSame('grace', $entries[1]->slug());
    }

    #[Test]
    public function imports_unmapped_types_into_a_collection_named_after_the_type()
    {
        Facades\Collection::make('designer')->save();

        $this->mockMetaobjectPages(['designer' => [$this->designer()]]);

        $this->artisan('shopify:import:metaobjects', ['type' => 'designer'])->assertSuccessful();

        $this->assertSame(['ada'], Facades\Entry::whereCollection('designer')->map->slug()->all());
    }

    #[Test]
    public function imports_every_mapped_type_into_its_own_collection_without_a_type()
    {
        config()->set('shopify.metaobjects.collections', ['designer' => 'designers', 'studio' => 'studios']);

        Facades\Collection::make('designers')->save();
        Facades\Collection::make('studios')->save();

        // handles are only unique per type, so both are "ada"
        $this->mockMetaobjectPages([
            'designer' => [$this->designer()],
            'studio' => [array_merge($this->designer(), ['id' => 'gid://shopify/Metaobject/9', 'type' => 'studio', 'displayName' => 'Ada Studio'])],
        ]);

        $this->artisan('shopify:import:metaobjects')->assertSuccessful();

        $this->assertSame('Ada', Facades\Entry::whereCollection('designers')->first()->get('title'));
        $this->assertSame('Ada Studio', Facades\Entry::whereCollection('studios')->first()->get('title'));
    }

    #[Test]
    public function metaobjects_command_fails_without_the_collection()
    {
        config()->set('shopify.metaobjects.collections', ['designer' => 'designers']);

        $this->artisan('shopify:import:metaobjects', ['type' => 'designer'])->assertFailed();
        $this->artisan('shopify:import:metaobjects', ['type' => 'studio'])->assertFailed();
    }

    #[Test]
    public function metaobjects_command_fails_without_a_type_or_mapping()
    {
        $this->artisan('shopify:import:metaobjects')->assertFailed();
        $this->artisan('shopify:import:metaobjects', ['--collection' => 'designers'])->assertFailed();
    }

    private function mockMetaobjectPages(array $nodesByType): void
    {
        $this->mock(Graphql::class, function (MockInterface $mock) use ($nodesByType) {
            foreach ($nodesByType as $type => $nodes) {
                $mock->shouldReceive('query')
                    ->withArgs(fn ($params) => str_contains($params['query'], 'metaobjects(type:') && $params['variables']['type'] === $type)
                    ->andReturn(new HttpResponse(status: 200, body: json_encode(['data' => ['metaobjects' => [
                        'nodes' => $nodes,
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    ]]])));
            }
        });
    }

    private function makeProductCollections(): void
    {
        Facades\Collection::make('products')->save();
        Facades\Collection::make('variants')->save();

        foreach (['collections', 'tags', 'type', 'vendor'] as $taxonomy) {
            Facades\Taxonomy::make()->handle($taxonomy)->save();
        }
    }

    private function designer(): array
    {
        return [
            'id' => 'gid://shopify/Metaobject/1',
            'handle' => 'ada',
            'type' => 'designer',
            'displayName' => 'Ada',
            'fields' => [
                ['key' => 'name', 'type' => 'single_line_text_field', 'value' => 'Ada', 'reference' => null, 'references' => null],
                ['key' => 'photo', 'type' => 'file_reference', 'value' => 'gid://shopify/MediaImage/1', 'reference' => ['id' => 'gid://shopify/MediaImage/1', 'image' => ['altText' => null, 'url' => 'https://cdn.shopify.com/ada.jpg']], 'references' => null],
                ['key' => 'studio', 'type' => 'metaobject_reference', 'value' => 'gid://shopify/Metaobject/2', 'reference' => [
                    'id' => 'gid://shopify/Metaobject/2',
                    'handle' => 'studio-ada',
                    'type' => 'studio',
                    'fields' => [
                        ['key' => 'city', 'type' => 'single_line_text_field', 'value' => 'London', 'reference' => null, 'references' => null],
                        ['key' => 'logo', 'type' => 'file_reference', 'value' => 'gid://shopify/GenericFile/1', 'reference' => ['id' => 'gid://shopify/GenericFile/1', 'url' => 'https://cdn.shopify.com/logo.png'], 'references' => null],
                    ],
                ], 'references' => null],
                ['key' => 'awards', 'type' => 'list.single_line_text_field', 'value' => '["Best in show"]', 'reference' => null, 'references' => null],
            ],
        ];
    }

    private function feature(int $id, string $label): array
    {
        return [
            'id' => 'gid://shopify/Metaobject/'.$id,
            'handle' => 'feature-'.$id,
            'type' => 'feature',
            'displayName' => $label,
            'fields' => [
                ['key' => 'label', 'type' => 'single_line_text_field', 'value' => $label, 'reference' => null, 'references' => null],
            ],
        ];
    }

    private function product(array $productMetafields = [], array $variantMetafields = []): array
    {
        $edges = fn ($metafields) => ['edges' => array_map(fn ($node) => ['node' => $node], $metafields)];

        return ['data' => ['product' => [
            'collections' => ['edges' => []],
            'descriptionHtml' => '',
            'handle' => 'product',
            'id' => 'gid://shopify/Product/1',
            'metafields' => $edges($productMetafields),
            'media' => ['edges' => []],
            'options' => [['name' => 'Title']],
            'productType' => 'Boards',
            'resourcePublications' => ['edges' => []],
            'tags' => [],
            'title' => 'Product',
            'variants' => [
                'edges' => [['node' => [
                    'compareAtPrice' => null,
                    'id' => 'gid://shopify/ProductVariant/1',
                    'inventoryItem' => ['measurement' => ['weight' => ['value' => 0]], 'requiresShipping' => true, 'tracked' => true],
                    'inventoryPolicy' => 'DENY',
                    'inventoryQuantity' => 0,
                    'media' => ['edges' => []],
                    'metafields' => $edges($variantMetafields),
                    'price' => '10.00',
                    'selectedOptions' => [],
                    'sku' => 'SKU1',
                    'title' => 'Default Title',
                ]]],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ],
            'vendor' => 'Arbor',
        ]]];
    }
}
