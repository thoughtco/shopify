<?php

namespace Thoughtco\Shopify\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades;
use Statamic\Fields\Field;
use Thoughtco\Shopify\Fieldtypes\SellingPlans;
use Thoughtco\Shopify\Tests\TestCase;

class SellingPlansFieldtypeTest extends TestCase
{
    private array $groups = [[
        'id' => 'gid://shopify/SellingPlanGroup/1',
        'name' => 'Subscribe & save',
        'app_id' => 'app',
        'selling_plans' => [
            [
                'id' => 'gid://shopify/SellingPlan/11',
                'name' => 'Every 2 weeks',
                'recurring_deliveries' => true,
                'interval' => 'WEEK',
                'interval_count' => 2,
                'pricing_policies' => [
                    ['adjustment_type' => 'PERCENTAGE', 'adjustment_value' => 10.0, 'after_cycle' => null],
                    ['adjustment_type' => 'PERCENTAGE', 'adjustment_value' => 15.5, 'after_cycle' => 3],
                ],
            ],
            [
                'id' => 'gid://shopify/SellingPlan/12',
                'name' => 'Prepaid',
                'recurring_deliveries' => false,
                'pricing_policies' => [['adjustment_type' => 'PRICE', 'adjustment_value' => 50.0, 'after_cycle' => null]],
            ],
        ],
    ]];

    #[Test]
    public function the_product_blueprint_shows_selling_plans_read_only_on_the_details_tab()
    {
        $field = Facades\Collection::find('products')->entryBlueprint()->field('selling_plan_groups');

        $this->assertInstanceOf(SellingPlans::class, $field->fieldtype());
        $this->assertSame('read_only', $field->get('visibility'));
        $this->assertArrayHasKey('selling_plan_groups', Facades\Collection::find('products')->entryBlueprint()->tabs()->get('shopify')->fields()->all());
    }

    #[Test]
    public function preloads_labels_for_each_plan_and_leaves_the_value_alone()
    {
        $field = (new Field('selling_plan_groups', ['type' => 'selling_plans']))->setValue($this->groups);

        $this->assertSame([
            'gid://shopify/SellingPlan/11' => ['delivery' => 'Every 2 weeks', 'pricing' => '10% off, 15.5% off after 3 deliveries'],
            'gid://shopify/SellingPlan/12' => ['delivery' => 'One delivery', 'pricing' => 'Price 50.00'],
        ], $field->meta()['labels']);

        $this->assertSame($this->groups, $field->preProcess()->value());
        $this->assertSame($this->groups, $field->process()->value());
    }

    #[Test]
    public function labels_single_intervals_fixed_amounts_and_plans_without_adjustments()
    {
        $this->assertSame('Every month', SellingPlans::deliveryLabel(['recurring_deliveries' => true, 'interval' => 'MONTH', 'interval_count' => 1]));
        $this->assertSame('Recurring', SellingPlans::deliveryLabel(['recurring_deliveries' => true]));
        $this->assertSame('5.00 off', SellingPlans::pricingLabel(['pricing_policies' => [['adjustment_type' => 'FIXED_AMOUNT', 'adjustment_value' => 5]]]));
        $this->assertSame('Full price', SellingPlans::pricingLabel(['pricing_policies' => []]));
    }
}
