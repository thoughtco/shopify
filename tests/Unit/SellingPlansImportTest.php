<?php

namespace Thoughtco\Shopify\Tests\Unit;

use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Facades;
use Statamic\Facades\Blink;
use Thoughtco\Shopify\Clients\Graphql;
use Thoughtco\Shopify\Clients\HttpResponse;
use Thoughtco\Shopify\Jobs\ImportSingleProductJob;
use Thoughtco\Shopify\Listeners;
use Thoughtco\Shopify\Support\SellingPlans;
use Thoughtco\Shopify\Tests\TestCase;

class SellingPlansImportTest extends TestCase
{
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();

        Facades\Taxonomy::make()->handle('collections')->save();
        Facades\Taxonomy::make()->handle('tags')->save();
        Facades\Taxonomy::make()->handle('type')->save();
        Facades\Taxonomy::make()->handle('vendor')->save();
    }

    #[Test]
    public function imports_selling_plan_groups_onto_the_product_and_plan_ids_onto_each_variant()
    {
        config()->set('shopify.selling_plans', true);
        $this->mockGraphql();

        ImportSingleProductJob::dispatch(108828309);

        $this->assertStringContainsString('requiresSellingPlan', $this->queries[0]);
        $this->assertStringContainsString('sellingPlanGroups(first: 20)', $this->queries[0]);

        $product = Facades\Entry::whereCollection('products')->first();

        $this->assertTrue($product->get('requires_selling_plan'));
        $this->assertSame([
            [
                'id' => 'gid://shopify/SellingPlanGroup/1',
                'name' => 'Subscribe & save',
                'app_id' => 'shopify-subscriptions',
                'options' => ['Delivery every'],
                'selling_plans' => [
                    [
                        'id' => 'gid://shopify/SellingPlan/11',
                        'name' => 'Monthly, 10% off',
                        'description' => null,
                        'options' => ['1 month'],
                        'recurring_deliveries' => true,
                        'interval' => 'MONTH',
                        'interval_count' => 1,
                        'pricing_policies' => [
                            ['adjustment_type' => 'PERCENTAGE', 'adjustment_value' => 10.0, 'after_cycle' => null],
                            ['adjustment_type' => 'PERCENTAGE', 'adjustment_value' => 20.0, 'after_cycle' => 3],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'gid://shopify/SellingPlanGroup/2',
                'name' => 'Prepaid',
                'app_id' => null,
                'options' => ['Bundle'],
                'selling_plans' => [
                    [
                        'id' => 'gid://shopify/SellingPlan/21',
                        'name' => '3 months prepaid',
                        'description' => 'Pay once',
                        'options' => ['3 months'],
                        'recurring_deliveries' => false,
                        'interval' => null,
                        'interval_count' => null,
                        'pricing_policies' => [
                            ['adjustment_type' => 'FIXED_AMOUNT', 'adjustment_value' => 5.0, 'after_cycle' => null],
                        ],
                    ],
                ],
            ],
        ], $product->get('selling_plan_groups'));

        $variants = Facades\Entry::whereCollection('variants')->keyBy(fn ($entry) => $entry->slug());

        $this->assertSame(['gid://shopify/SellingPlan/11', 'gid://shopify/SellingPlan/21'], $variants['1']->get('selling_plan_ids'));
        $this->assertSame(['gid://shopify/SellingPlan/11'], $variants['2']->get('selling_plan_ids'));
    }

    #[Test]
    public function products_without_selling_plans_get_empty_values()
    {
        config()->set('shopify.selling_plans', true);
        $this->mockGraphql(withPlans: false);

        ImportSingleProductJob::dispatch(108828309);

        $product = Facades\Entry::whereCollection('products')->first();

        $this->assertFalse($product->get('requires_selling_plan'));
        $this->assertSame([], $product->get('selling_plan_groups'));
        $this->assertSame([], Facades\Entry::whereCollection('variants')->first()->get('selling_plan_ids'));
    }

    #[Test]
    public function selling_plans_are_not_queried_unless_enabled()
    {
        $this->mockGraphql();

        ImportSingleProductJob::dispatch(108828309);

        $this->assertStringNotContainsString('sellingPlanGroups', $this->queries[0]);
        $this->assertStringNotContainsString('requiresSellingPlan', $this->queries[0]);

        $product = Facades\Entry::whereCollection('products')->first();

        $this->assertNull($product->get('selling_plan_groups'));
        $this->assertNull($product->get('requires_selling_plan'));
        $this->assertNull(Facades\Entry::whereCollection('variants')->first()->get('selling_plan_ids'));
    }

    #[Test]
    public function unified_multi_store_keeps_each_stores_selling_plans()
    {
        config()->set('shopify.selling_plans', true);
        config()->set('shopify.multi_store', [
            'enabled' => true,
            'mode' => 'unified',
            'primary_store' => 'uk',
            'stores' => [
                'uk' => ['url' => 'uk-store.myshopify.com', 'admin_token' => 'uk-token'],
                'us' => ['url' => 'us-store.myshopify.com', 'admin_token' => 'us-token'],
            ],
        ]);

        $this->app->instance('shopify.graphql.us', $this->mockGraphql());

        ImportSingleProductJob::dispatch(108828309, [], 'us');

        $product = Facades\Entry::whereCollection('products')->first();

        // the primary store's plans are the product's own
        $this->assertNull($product->get('selling_plan_groups'));
        $this->assertTrue($product->get('multi_store_selling_plans')['us']['requires_selling_plan']);
        $this->assertCount(2, $product->get('multi_store_selling_plans')['us']['selling_plan_groups']);

        $variant = Facades\Entry::whereCollection('variants')->firstWhere('slug', '2');

        $this->assertNull($variant->get('selling_plan_ids'));
        $this->assertSame(['gid://shopify/SellingPlan/11'], $variant->get('multi_store_data')['us']['selling_plan_ids']);
    }

    #[Test]
    public function works_out_the_price_on_a_plan()
    {
        $plan = fn ($type, $value, $afterCycle = null) => ['pricing_policies' => [
            ['adjustment_type' => $type, 'adjustment_value' => $value, 'after_cycle' => $afterCycle],
        ]];

        $this->assertSame(20.7, SellingPlans::price('23.00', $plan('PERCENTAGE', 10)));
        $this->assertSame(18.0, SellingPlans::price('23.00', $plan('FIXED_AMOUNT', 5)));
        $this->assertSame(0.0, SellingPlans::price('23.00', $plan('FIXED_AMOUNT', 50)));
        $this->assertSame(15.0, SellingPlans::price('23.00', $plan('PRICE', 15)));
        $this->assertSame(23.0, SellingPlans::price('23.00', ['pricing_policies' => []]));
        $this->assertNull(SellingPlans::price(null, $plan('PERCENTAGE', 10)));

        // only the first delivery's policy changes the price shown
        $this->assertSame(20.0, SellingPlans::price('20', $plan('PERCENTAGE', 10, 3)));

        // fixed amounts are in the shop's currency, so aren't taken off a market's price
        $this->assertNull(SellingPlans::price('23.00', $plan('FIXED_AMOUNT', 5), inShopCurrency: false));
        $this->assertNull(SellingPlans::price('23.00', $plan('PRICE', 5), inShopCurrency: false));
        $this->assertSame(11.5, SellingPlans::price('23.00', $plan('PERCENTAGE', 50), inShopCurrency: false));
    }

    #[Test]
    public function the_subscriptions_section_is_added_to_the_details_tab_when_selling_plans_are_enabled()
    {
        config()->set('shopify.selling_plans', true);
        Blink::flush(); // the blueprint found event fires once per request

        $sections = collect(Facades\Collection::find('products')->entryBlueprint()->contents()['tabs']['shopify']['sections']);

        $this->assertSame(['requires_selling_plan', 'selling_plan_groups'], array_column($sections->firstWhere('display', 'Subscriptions')['fields'], 'handle'));
        $this->assertSame('Shopify record', $sections->last()['display']);
        $this->assertTrue(Facades\Collection::find('variants')->entryBlueprint()->hasField('selling_plan_ids'));

        // the blueprint files are untouched
        $this->assertStringNotContainsString('selling_plan', file_get_contents(__DIR__.'/../../resources/blueprints/product.yaml'));
    }

    #[Test]
    public function the_subscriptions_section_is_only_added_once()
    {
        config()->set('shopify.selling_plans', true);
        Blink::flush(); // the blueprint found event fires once per request

        $blueprint = Facades\Collection::find('products')->entryBlueprint();

        (new Listeners\AddSellingPlanFieldsToBlueprints)->handle(new EntryBlueprintFound($blueprint));

        $this->assertSame(1, collect($blueprint->contents()['tabs']['shopify']['sections'])->where('display', 'Subscriptions')->count());
    }

    #[Test]
    public function there_is_no_subscriptions_section_when_selling_plans_are_disabled()
    {
        config()->set('shopify.selling_plans', false);
        Blink::flush();

        $this->assertFalse(Facades\Collection::find('products')->entryBlueprint()->hasField('selling_plan_groups'));
        $this->assertFalse(Facades\Collection::find('variants')->entryBlueprint()->hasField('selling_plan_ids'));
    }

    private function mockGraphql(bool $withPlans = true): MockInterface
    {
        return $this->mock(Graphql::class, function (MockInterface $mock) use ($withPlans) {
            $mock->shouldReceive('query')->andReturnUsing(function ($query) use ($withPlans) {
                $this->queries[] = $query['query'];

                return new HttpResponse(status: 200, body: json_encode($this->product($withPlans)));
            });
        });
    }

    private function product(bool $withPlans): array
    {
        $variant = fn ($id, $groups) => ['node' => [
            'compareAtPrice' => null,
            'id' => "gid://shopify/ProductVariant/{$id}",
            'inventoryItem' => ['requiresShipping' => true, 'tracked' => true],
            'inventoryPolicy' => 'DENY',
            'inventoryQuantity' => 10,
            'media' => ['edges' => []],
            'metafields' => ['edges' => []],
            'price' => '20.00',
            'selectedOptions' => [['name' => 'Size', 'optionValue' => ['id' => "gid://shopify/ProductOptionValue/{$id}"], 'value' => "Size {$id}"]],
            'sku' => "SKU-{$id}",
            'title' => "Size {$id}",
            'sellingPlanGroups' => ['nodes' => array_map(fn ($group) => ['id' => "gid://shopify/SellingPlanGroup/{$group}"], $withPlans ? $groups : [])],
        ]];

        return ['data' => ['product' => [
            'collections' => ['edges' => []],
            'descriptionHtml' => '<p>Coffee</p>',
            'handle' => 'coffee',
            'id' => 'gid://shopify/Product/108828309',
            'metafields' => ['edges' => []],
            'media' => ['edges' => []],
            'options' => [['name' => 'Size', 'values' => ['Size 1', 'Size 2']]],
            'productType' => 'Coffee',
            'requiresSellingPlan' => $withPlans,
            'resourcePublications' => ['edges' => []],
            'sellingPlanGroups' => ['nodes' => $withPlans ? [
                [
                    'id' => 'gid://shopify/SellingPlanGroup/1',
                    'name' => 'Subscribe & save',
                    'appId' => 'shopify-subscriptions',
                    'options' => ['Delivery every'],
                    'sellingPlans' => ['nodes' => [[
                        'id' => 'gid://shopify/SellingPlan/11',
                        'name' => 'Monthly, 10% off',
                        'description' => null,
                        'options' => ['1 month'],
                        'deliveryPolicy' => ['__typename' => 'SellingPlanRecurringDeliveryPolicy', 'interval' => 'MONTH', 'intervalCount' => 1],
                        'pricingPolicies' => [
                            ['__typename' => 'SellingPlanFixedPricingPolicy', 'adjustmentType' => 'PERCENTAGE', 'adjustmentValue' => ['percentage' => 10]],
                            ['__typename' => 'SellingPlanRecurringPricingPolicy', 'adjustmentType' => 'PERCENTAGE', 'afterCycle' => 3, 'adjustmentValue' => ['percentage' => 20]],
                        ],
                    ]]],
                ],
                [
                    'id' => 'gid://shopify/SellingPlanGroup/2',
                    'name' => 'Prepaid',
                    'appId' => null,
                    'options' => ['Bundle'],
                    'sellingPlans' => ['nodes' => [[
                        'id' => 'gid://shopify/SellingPlan/21',
                        'name' => '3 months prepaid',
                        'description' => 'Pay once',
                        'options' => ['3 months'],
                        'deliveryPolicy' => ['__typename' => 'SellingPlanFixedDeliveryPolicy'],
                        'pricingPolicies' => [
                            ['__typename' => 'SellingPlanFixedPricingPolicy', 'adjustmentType' => 'FIXED_AMOUNT', 'adjustmentValue' => ['amount' => '5.00']],
                        ],
                    ]]],
                ],
            ] : []],
            'tags' => [],
            'title' => 'Coffee',
            'variants' => [
                'edges' => [$variant(1, [1, 2]), $variant(2, [1])],
                'pageInfo' => ['hasNextPage' => false],
            ],
            'vendor' => 'Roaster',
        ]]];
    }
}
