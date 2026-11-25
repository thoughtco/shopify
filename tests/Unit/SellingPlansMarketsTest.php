<?php

namespace Thoughtco\Shopify\Tests\Unit;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades;
use Thoughtco\Shopify\Activity\Activity;
use Thoughtco\Shopify\Activity\ActivityLog;
use Thoughtco\Shopify\Clients\Graphql;
use Thoughtco\Shopify\Clients\HttpResponse;
use Thoughtco\Shopify\Contracts\ActivityRepository;
use Thoughtco\Shopify\Jobs\ImportSingleProductJob;
use Thoughtco\Shopify\Tests\TestCase;

class SellingPlansMarketsTest extends TestCase
{
    private const MONTHLY = 'gid://shopify/SellingPlan/11';

    private const WEEKLY = 'gid://shopify/SellingPlan/12';

    private const PREPAID = 'gid://shopify/SellingPlan/13';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['collections', 'tags', 'type', 'vendor'] as $taxonomy) {
            Facades\Taxonomy::make($taxonomy)->save();
        }

        config()->set('shopify.selling_plans', true);
        config()->set('shopify.url', 'my-store.myshopify.com');
        config()->set('shopify.storefront_token', 'public-token');
        config()->set('shopify.multi_store', [
            'enabled' => true,
            'mode' => 'markets',
            'markets' => [
                'GB' => ['currency' => '£'],
                'IE' => ['currency' => '€'],
            ],
        ]);
    }

    #[Test]
    public function stores_each_markets_exact_plan_prices_on_the_variants()
    {
        $this->mockAdmin();
        $this->fakeStorefront();

        ImportSingleProductJob::dispatch(108828309);

        $variant = Facades\Entry::whereCollection('variants')->first();

        $this->assertSame([
            self::MONTHLY => ['price' => '18.0', 'compare_at_price' => '20.0', 'per_delivery_price' => '18.0'],
            self::WEEKLY => ['price' => '17.0', 'compare_at_price' => '20.0', 'per_delivery_price' => '17.0'],
        ], $variant->get('market_data')['GB']['selling_plans']);

        $this->assertSame('21.5', $variant->get('market_data')['IE']['selling_plans'][self::MONTHLY]['price']);
        $this->assertSame('25.0', $variant->get('market_data')['IE']['price']);

        // one query per market, in that market's context, with the public token
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://my-store.myshopify.com/api/2026-01/graphql.json'
            && $request->hasHeader('X-Shopify-Storefront-Access-Token', 'public-token')
            && str_contains($request['query'], '@inContext(country: $country)')
            && $request['variables']['country'] === 'IE'
            && $request['variables']['id'] === 'gid://shopify/Product/108828309');
    }

    #[Test]
    public function uses_the_private_storefront_token_when_configured()
    {
        config()->set('shopify.storefront_private_token', 'private-token');

        $this->mockAdmin();
        $this->fakeStorefront();

        ImportSingleProductJob::dispatch(108828309);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Shopify-Storefront-Private-Token', 'private-token')
            && ! $request->hasHeader('X-Shopify-Storefront-Access-Token'));
    }

    #[Test]
    public function pages_through_variants()
    {
        $this->mockAdmin();

        Http::fake(function (Request $request) {
            $page = $request['variables']['cursor'] ?? null;

            return Http::response(['data' => ['product' => ['variants' => [
                'nodes' => [$this->storefrontVariant($page ? 2 : 1, ['18.0'])],
                'pageInfo' => ['hasNextPage' => ! $page, 'endCursor' => $page ? null : 'next'],
            ]]]]);
        });

        ImportSingleProductJob::dispatch(108828309);

        Http::assertSentCount(4);

        $variant = Facades\Entry::whereCollection('variants')->firstWhere('slug', '2');
        $this->assertSame('18.0', $variant->get('market_data')['GB']['selling_plans'][self::MONTHLY]['price']);
    }

    #[Test]
    public function imports_without_market_plan_prices_when_there_is_no_storefront_token()
    {
        config()->set('shopify.storefront_token', null);

        $this->mockAdmin();
        Http::fake();

        ImportSingleProductJob::dispatch(108828309);

        Http::assertNothingSent();

        $variant = Facades\Entry::whereCollection('variants')->first();
        $this->assertSame([], $variant->get('market_data')['GB']['selling_plans']);
        $this->assertSame('20.0', $variant->get('market_data')['GB']['price']);
    }

    #[Test]
    public function a_failed_storefront_query_does_not_stop_the_import()
    {
        $this->mockAdmin();

        Http::fake(fn (Request $request) => $request['variables']['country'] === 'IE'
            ? Http::response(['errors' => [['message' => 'Access denied']]], 200)
            : Http::response($this->storefrontResponse(['18.0', '17.0'])));

        $activityId = ActivityLog::queueProductImport(108828309, null, Activity::SOURCE_CLI);

        $variant = Facades\Entry::whereCollection('variants')->first();
        $this->assertNotNull($variant);
        $this->assertSame('18.0', $variant->get('market_data')['GB']['selling_plans'][self::MONTHLY]['price']);
        $this->assertSame([], $variant->get('market_data')['IE']['selling_plans']);

        $activity = app(ActivityRepository::class)->find($activityId);
        $this->assertSame('updated', $activity->status->value);
        $this->assertSame(['IE'], $activity->changes['selling_plan_prices_failed']);
    }

    #[Test]
    public function a_storefront_server_error_does_not_stop_the_import()
    {
        $this->mockAdmin();
        Http::fake(fn () => Http::response('', 503));

        ImportSingleProductJob::dispatch(108828309);

        $this->assertSame(1, Facades\Entry::whereCollection('products')->count());
        $this->assertSame([], Facades\Entry::whereCollection('variants')->first()->get('market_data')['GB']['selling_plans']);
    }

    #[Test]
    public function tags_use_the_stored_market_prices_for_every_adjustment_type()
    {
        $context = $this->makeProduct([
            'price' => '20.00',
            'selling_plan_ids' => [self::MONTHLY, self::WEEKLY, self::PREPAID],
            'market_data' => [
                'IE' => ['price' => '25.00', 'inventory_quantity' => 5, 'selling_plans' => [
                    self::MONTHLY => ['price' => '22.00'],
                    self::WEEKLY => ['price' => '21.00'],
                    self::PREPAID => ['price' => '6.50'],
                ]],
            ],
        ]);

        $this->assertSame(
            'Monthly=€22.00;Weekly=€21.00;Prepaid=€6.50;',
            $this->tag('{{ shopify:selling_plans store="IE" }}{{ plans }}{{ name }}={{ price }};{{ /plans }}{{ /shopify:selling_plans }}', $context)
        );

        $this->assertSame('€6.50', $this->tag('{{ shopify:product_price store="IE" subscription="true" }}', $context));
        $this->assertSame('€21.00', $this->tag('{{ shopify:product_price store="IE" selling_plan="'.self::WEEKLY.'" }}', $context));

        // the shop's own prices are unchanged
        $this->assertSame('Monthly=£18.00;Weekly=£17.00;Prepaid=£5.00;', $this->tag('{{ shopify:selling_plans }}{{ plans }}{{ name }}={{ price }};{{ /plans }}{{ /shopify:selling_plans }}', $context));
    }

    #[Test]
    public function tags_fall_back_to_percentage_maths_without_stored_market_prices()
    {
        $context = $this->makeProduct([
            'price' => '20.00',
            'selling_plan_ids' => [self::MONTHLY, self::WEEKLY, self::PREPAID],
            'market_data' => ['IE' => ['price' => '25.00', 'inventory_quantity' => 5, 'selling_plans' => []]],
        ]);

        $this->assertSame(
            'Monthly=€22.50;Weekly=;Prepaid=;',
            $this->tag('{{ shopify:selling_plans store="IE" }}{{ plans }}{{ name }}={{ price }};{{ /plans }}{{ /shopify:selling_plans }}', $context)
        );
        $this->assertSame('', $this->tag('{{ shopify:product_price store="IE" selling_plan="'.self::PREPAID.'" }}', $context));
    }

    private function tag($tag, $variables = [])
    {
        return trim((string) Facades\Parse::template($tag, $variables, trusted: true));
    }

    private function sellingPlanGroups(): array
    {
        $plan = fn ($id, $name, $type, $value) => [
            'id' => $id,
            'name' => $name,
            'pricing_policies' => [['adjustment_type' => $type, 'adjustment_value' => $value, 'after_cycle' => null]],
        ];

        return [[
            'id' => 'gid://shopify/SellingPlanGroup/1',
            'name' => 'Subscribe & save',
            'selling_plans' => [
                $plan(self::MONTHLY, 'Monthly', 'PERCENTAGE', 10),
                $plan(self::WEEKLY, 'Weekly', 'FIXED_AMOUNT', 3),
                $plan(self::PREPAID, 'Prepaid', 'PRICE', 5),
            ],
        ]];
    }

    private function makeProduct(array $variant): array
    {
        $data = ['title' => 'Coffee', 'product_id' => '1', 'selling_plan_groups' => $this->sellingPlanGroups()];

        Facades\Entry::make()->collection('products')->slug('coffee')->data($data)->save();
        Facades\Entry::make()->collection('variants')->slug('100')->data(array_merge([
            'title' => 'Bag',
            'variant_id' => 100,
            'product_slug' => 'coffee',
            'inventory_quantity' => 5,
            'inventory_policy' => 'deny',
        ], $variant))->save();

        return $data + ['slug' => 'coffee'];
    }

    private function fakeStorefront(): void
    {
        Http::fake(fn (Request $request) => Http::response($this->storefrontResponse(
            $request['variables']['country'] === 'GB' ? ['18.0', '17.0'] : ['21.5', '20.0'],
            $request['variables']['country'] === 'GB' ? '20.0' : '25.0',
        )));
    }

    private function storefrontResponse(array $prices, string $compareAt = '20.0'): array
    {
        return ['data' => ['product' => ['variants' => [
            'nodes' => [$this->storefrontVariant(1, $prices, $compareAt)],
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
        ]]]];
    }

    private function storefrontVariant(int $id, array $prices, string $compareAt = '20.0'): array
    {
        $money = fn ($amount) => ['amount' => $amount, 'currencyCode' => 'GBP'];

        return [
            'id' => "gid://shopify/ProductVariant/{$id}",
            'sellingPlanAllocations' => ['nodes' => collect([self::MONTHLY, self::WEEKLY])
                ->take(count($prices))
                ->values()
                ->map(fn ($planId, $index) => [
                    'sellingPlan' => ['id' => $planId],
                    'priceAdjustments' => [[
                        'price' => $money($prices[$index]),
                        'compareAtPrice' => $money($compareAt),
                        'perDeliveryPrice' => $money($prices[$index]),
                    ]],
                ])
                ->all()],
        ];
    }

    private function mockAdmin(): void
    {
        $variant = fn ($id) => ['node' => [
            'compareAtPrice' => null,
            'id' => "gid://shopify/ProductVariant/{$id}",
            'inventoryItem' => ['requiresShipping' => true, 'tracked' => true, 'inventoryLevels' => ['nodes' => []]],
            'inventoryPolicy' => 'DENY',
            'inventoryQuantity' => 10,
            'media' => ['edges' => []],
            'metafields' => ['edges' => []],
            'price' => '20.00',
            'selectedOptions' => [['name' => 'Size', 'optionValue' => ['id' => "gid://shopify/ProductOptionValue/{$id}"], 'value' => "Size {$id}"]],
            'sku' => "SKU-{$id}",
            'title' => "Size {$id}",
            'contextualPricingGB' => ['price' => ['amount' => '20.0', 'currencyCode' => 'GBP'], 'compareAtPrice' => null],
            'contextualPricingIE' => ['price' => ['amount' => '25.0', 'currencyCode' => 'EUR'], 'compareAtPrice' => null],
            'sellingPlanGroups' => ['nodes' => [['id' => 'gid://shopify/SellingPlanGroup/1']]],
        ]];

        $product = ['data' => ['product' => [
            'collections' => ['edges' => []],
            'descriptionHtml' => '',
            'handle' => 'coffee',
            'id' => 'gid://shopify/Product/108828309',
            'metafields' => ['edges' => []],
            'media' => ['edges' => []],
            'options' => [['name' => 'Size', 'values' => ['Size 1', 'Size 2']]],
            'productType' => 'Coffee',
            'requiresSellingPlan' => false,
            'resourcePublications' => ['edges' => []],
            'sellingPlanGroups' => ['nodes' => [[
                'id' => 'gid://shopify/SellingPlanGroup/1',
                'name' => 'Subscribe & save',
                'appId' => null,
                'options' => [],
                'sellingPlans' => ['nodes' => [
                    ['id' => self::MONTHLY, 'name' => 'Monthly', 'options' => [], 'deliveryPolicy' => [], 'pricingPolicies' => []],
                    ['id' => self::WEEKLY, 'name' => 'Weekly', 'options' => [], 'deliveryPolicy' => [], 'pricingPolicies' => []],
                ]],
            ]]],
            'tags' => [],
            'title' => 'Coffee',
            'variants' => ['edges' => [$variant(1), $variant(2)], 'pageInfo' => ['hasNextPage' => false]],
            'vendor' => 'Roaster',
        ]]];

        $this->mock(Graphql::class, function (MockInterface $mock) use ($product) {
            $mock->shouldReceive('query')->andReturn(new HttpResponse(status: 200, body: json_encode($product)));
        });
    }
}
