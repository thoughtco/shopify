<?php

namespace Thoughtco\Shopify\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades;
use Thoughtco\Shopify\Tests\TestCase;

class SellingPlanTagsTest extends TestCase
{
    private function tag($tag, $variables = [])
    {
        return trim((string) Facades\Parse::template($tag, $variables, trusted: true));
    }

    private function sellingPlanGroups(): array
    {
        $plan = fn ($id, $name, $type, $value) => [
            'id' => "gid://shopify/SellingPlan/{$id}",
            'name' => $name,
            'description' => null,
            'options' => [$name],
            'recurring_deliveries' => true,
            'interval' => 'MONTH',
            'interval_count' => 1,
            'pricing_policies' => [['adjustment_type' => $type, 'adjustment_value' => $value, 'after_cycle' => null]],
        ];

        return [
            [
                'id' => 'gid://shopify/SellingPlanGroup/1',
                'name' => 'Subscribe & save',
                'app_id' => 'app',
                'options' => ['Delivery every'],
                'selling_plans' => [$plan(11, 'Monthly', 'PERCENTAGE', 10), $plan(12, 'Weekly', 'FIXED_AMOUNT', 3)],
            ],
            [
                'id' => 'gid://shopify/SellingPlanGroup/2',
                'name' => 'Prepaid',
                'app_id' => null,
                'options' => ['Bundle'],
                'selling_plans' => [$plan(21, 'Prepaid', 'PRICE', 5)],
            ],
        ];
    }

    private function makeProduct(array $productData = [], ?array $variants = null): array
    {
        $data = array_merge([
            'title' => 'Coffee',
            'product_id' => '1',
            'requires_selling_plan' => false,
            'selling_plan_groups' => $this->sellingPlanGroups(),
        ], $productData);

        Facades\Entry::make()->collection('products')->slug('coffee')->data($data)->save();

        $variants ??= [
            ['slug' => '100', 'variant_id' => 100, 'price' => '20.00', 'selling_plan_ids' => ['gid://shopify/SellingPlan/11', 'gid://shopify/SellingPlan/12', 'gid://shopify/SellingPlan/21']],
            ['slug' => '101', 'variant_id' => 101, 'price' => '30.00', 'selling_plan_ids' => ['gid://shopify/SellingPlan/12']],
        ];

        foreach ($variants as $variant) {
            Facades\Entry::make()->collection('variants')->slug($variant['slug'])->data(array_merge([
                'title' => 'Bag',
                'product_slug' => 'coffee',
                'inventory_quantity' => 5,
                'inventory_policy' => 'deny',
            ], array_diff_key($variant, ['slug' => true])))->save();
        }

        return array_merge($data, ['slug' => 'coffee']);
    }

    #[Test]
    public function lists_the_products_selling_plans_priced_from_its_lowest_priced_variant()
    {
        $context = $this->makeProduct();

        $this->assertSame(
            'yes|no|Subscribe & save: Monthly=£18.00 (PERCENTAGE 10), Weekly=£17.00 (FIXED_AMOUNT 3), |Prepaid: Prepaid=£5.00 (PRICE 5), |',
            $this->tag('{{ shopify:selling_plans }}{{ if has_selling_plans }}yes{{ /if }}|{{ if requires_selling_plan }}yes{{ else }}no{{ /if }}|{{ groups }}{{ name }}: {{ selling_plans }}{{ name }}={{ price }} ({{ adjustment_type }} {{ adjustment_value }}), {{ /selling_plans }}|{{ /groups }}{{ /shopify:selling_plans }}', $context)
        );
    }

    #[Test]
    public function lists_only_a_variants_own_plans()
    {
        $context = $this->makeProduct();

        $this->assertSame(
            'Weekly=£27.00;',
            $this->tag('{{ shopify:selling_plans variant="101" }}{{ plans }}{{ name }}={{ price }};{{ /plans }}{{ /shopify:selling_plans }}', $context)
        );

        // by slug, without a product in context
        $this->assertSame(
            'Weekly=£27.00;',
            $this->tag('{{ shopify:selling_plans slug="coffee" variant="101" }}{{ plans }}{{ name }}={{ price }};{{ /plans }}{{ /shopify:selling_plans }}')
        );
    }

    #[Test]
    public function products_without_plans_have_none()
    {
        $context = $this->makeProduct(['selling_plan_groups' => null, 'requires_selling_plan' => null]);

        $this->assertSame('none', $this->tag('{{ shopify:selling_plans }}{{ unless has_selling_plans }}none{{ /unless }}{{ /shopify:selling_plans }}', $context));
        $this->assertSame('', $this->tag('{{ shopify:product_price subscription="true" }}', $context));
    }

    #[Test]
    public function product_price_shows_the_lowest_subscription_price()
    {
        $context = $this->makeProduct();

        // a one-off purchase is unchanged
        $this->assertSame('£20.00', $this->tag('{{ shopify:product_price }}', $context));

        $this->assertSame('£5.00', $this->tag('{{ shopify:product_price subscription="true" }}', $context));
        $this->assertSame('£18.00', $this->tag('{{ shopify:product_price selling_plan="gid://shopify/SellingPlan/11" }}', $context));

        // only variant 100 has the monthly plan; both have the weekly one
        $this->assertSame('From £17.00', $this->tag('{{ shopify:product_price selling_plan="gid://shopify/SellingPlan/12" show_from="true" }}', $context));
        $this->assertSame('', $this->tag('{{ shopify:product_price selling_plan="gid://shopify/SellingPlan/999" }}', $context));
    }

    #[Test]
    public function variants_imported_before_selling_plans_can_use_all_of_them()
    {
        $context = $this->makeProduct(variants: [['slug' => '100', 'variant_id' => 100, 'price' => '10.00']]);

        $this->assertSame('£9.00', $this->tag('{{ shopify:product_price selling_plan="gid://shopify/SellingPlan/11" }}', $context));
    }

    #[Test]
    public function requires_selling_plan_is_output_for_the_bundled_js()
    {
        $context = $this->makeProduct(['requires_selling_plan' => true]);

        $this->assertSame(
            'true',
            $this->tag('{{ $plans = {shopify:selling_plans} }}{{ $plans.requires_selling_plan | to_json }}', $context)
        );
    }

    #[Test]
    public function markets_only_apply_percentage_adjustments_to_market_prices()
    {
        config()->set('shopify.multi_store', [
            'enabled' => true,
            'mode' => 'markets',
            'markets' => ['IE' => ['currency' => '€']],
        ]);

        $context = $this->makeProduct(variants: [[
            'slug' => '100',
            'variant_id' => 100,
            'price' => '20.00',
            'market_data' => ['IE' => ['price' => '30.00', 'inventory_quantity' => 5]],
            'selling_plan_ids' => ['gid://shopify/SellingPlan/11', 'gid://shopify/SellingPlan/12'],
        ]]);

        $this->assertSame(
            'Monthly=€27.00;Weekly=;',
            $this->tag('{{ shopify:selling_plans store="IE" }}{{ plans }}{{ name }}={{ price }};{{ /plans }}{{ /shopify:selling_plans }}', $context)
        );
        $this->assertSame('€27.00', $this->tag('{{ shopify:product_price store="IE" subscription="true" }}', $context));
    }

    #[Test]
    public function unified_multi_store_uses_the_stores_plans()
    {
        config()->set('shopify.multi_store', [
            'enabled' => true,
            'mode' => 'unified',
            'primary_store' => 'uk',
            'stores' => [
                'uk' => ['url' => 'uk.myshopify.com', 'currency' => '£'],
                'us' => ['url' => 'us.myshopify.com', 'currency' => '$'],
            ],
        ]);

        $usGroups = [[
            'id' => 'gid://shopify/SellingPlanGroup/9',
            'name' => 'US plans',
            'selling_plans' => [[
                'id' => 'gid://shopify/SellingPlan/91',
                'name' => 'US monthly',
                'pricing_policies' => [['adjustment_type' => 'PERCENTAGE', 'adjustment_value' => 50, 'after_cycle' => null]],
            ]],
        ]];

        $context = $this->makeProduct(
            ['multi_store_selling_plans' => ['us' => ['requires_selling_plan' => true, 'selling_plan_groups' => $usGroups]]],
            [[
                'slug' => '100',
                'variant_id' => 100,
                'price' => '20.00',
                'selling_plan_ids' => ['gid://shopify/SellingPlan/11'],
                'multi_store_data' => ['us' => ['price' => '24.00', 'selling_plan_ids' => ['gid://shopify/SellingPlan/91']]],
            ]],
        );

        $this->assertSame(
            'yes|US monthly=$12.00;',
            $this->tag('{{ shopify:selling_plans store="us" }}{{ if requires_selling_plan }}yes{{ /if }}|{{ plans }}{{ name }}={{ price }};{{ /plans }}{{ /shopify:selling_plans }}', $context)
        );
        $this->assertSame('|Monthly=£18.00;', $this->tag('{{ shopify:selling_plans }}{{ if requires_selling_plan }}yes{{ /if }}|{{ plans }}{{ name }}={{ price }};{{ /plans }}{{ /shopify:selling_plans }}', $context));
    }
}
