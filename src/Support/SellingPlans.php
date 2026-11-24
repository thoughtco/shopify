<?php

namespace Thoughtco\Shopify\Support;

use Statamic\Support\Arr;

/**
 * Selling plans (subscriptions and other purchase options) created by whichever app
 * the merchant uses. The addon only reads them: the import stores a product's groups
 * and each variant's plan ids, the tags and the bundled JS show them and add them to
 * the cart. Mirrored in resources/js/shopify/selling-plans.js.
 *
 * A stored group:
 * ['id', 'name', 'app_id', 'options', 'selling_plans' => [
 *     ['id', 'name', 'description', 'options', 'recurring_deliveries', 'interval', 'interval_count',
 *      'pricing_policies' => [['adjustment_type', 'adjustment_value', 'after_cycle']]],
 * ]]
 */
class SellingPlans
{
    public const PRODUCT_FIELDS = ['requires_selling_plan', 'selling_plan_groups'];

    public const VARIANT_FIELDS = ['selling_plan_ids'];

    public const PERCENTAGE = 'PERCENTAGE';

    public const FIXED_AMOUNT = 'FIXED_AMOUNT';

    public const PRICE = 'PRICE';

    public static function enabled(): bool
    {
        return (bool) config('shopify.selling_plans', false);
    }

    /**
     * Admin API fields for the product query.
     */
    public static function productQueryFields(): string
    {
        return <<<'GQL'
            requiresSellingPlan
            sellingPlanGroups(first: 20) {
              nodes {
                id
                name
                appId
                options
                sellingPlans(first: 20) {
                  nodes {
                    id
                    name
                    description
                    options
                    deliveryPolicy {
                      __typename
                      ... on SellingPlanRecurringDeliveryPolicy {
                        interval
                        intervalCount
                      }
                    }
                    pricingPolicies {
                      __typename
                      ... on SellingPlanFixedPricingPolicy {
                        adjustmentType
                        adjustmentValue {
                          ... on MoneyV2 {
                            amount
                          }
                          ... on SellingPlanPricingPolicyPercentageValue {
                            percentage
                          }
                        }
                      }
                      ... on SellingPlanRecurringPricingPolicy {
                        adjustmentType
                        afterCycle
                        adjustmentValue {
                          ... on MoneyV2 {
                            amount
                          }
                          ... on SellingPlanPricingPolicyPercentageValue {
                            percentage
                          }
                        }
                      }
                    }
                  }
                }
              }
            }
            GQL;
    }

    /**
     * Admin API fields for the variant query: the groups the variant is in.
     */
    public static function variantQueryFields(): string
    {
        return <<<'GQL'
            sellingPlanGroups(first: 20) {
              nodes {
                id
              }
            }
            GQL;
    }

    /**
     * The product's selling plan groups, from the Admin API response.
     */
    public static function groupsFromGraphql(array $product): array
    {
        return collect(Arr::get($product, 'sellingPlanGroups.nodes', []))
            ->map(fn ($group) => [
                'id' => $group['id'] ?? null,
                'name' => $group['name'] ?? null,
                'app_id' => $group['appId'] ?? null,
                'options' => array_values($group['options'] ?? []),
                'selling_plans' => collect(Arr::get($group, 'sellingPlans.nodes', []))
                    ->map(fn ($plan) => static::planFromGraphql($plan))
                    ->values()
                    ->all(),
            ])
            ->filter(fn ($group) => $group['id'])
            ->values()
            ->all();
    }

    private static function planFromGraphql(array $plan): array
    {
        $recurring = Arr::get($plan, 'deliveryPolicy.__typename') === 'SellingPlanRecurringDeliveryPolicy';

        return [
            'id' => $plan['id'] ?? null,
            'name' => $plan['name'] ?? null,
            'description' => $plan['description'] ?? null,
            'options' => array_values($plan['options'] ?? []),
            'recurring_deliveries' => $recurring,
            'interval' => $recurring ? Arr::get($plan, 'deliveryPolicy.interval') : null,
            'interval_count' => $recurring ? Arr::get($plan, 'deliveryPolicy.intervalCount') : null,
            'pricing_policies' => collect($plan['pricingPolicies'] ?? [])
                ->filter(fn ($policy) => isset($policy['adjustmentType']))
                ->map(fn ($policy) => [
                    'adjustment_type' => $policy['adjustmentType'],
                    'adjustment_value' => (float) (Arr::get($policy, 'adjustmentValue.percentage') ?? Arr::get($policy, 'adjustmentValue.amount') ?? 0),
                    'after_cycle' => $policy['afterCycle'] ?? null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The ids of the plans a variant can be bought on: those in the groups it's in.
     */
    public static function variantPlanIds(array $variant, array $groups): array
    {
        $groupIds = collect(Arr::get($variant, 'sellingPlanGroups.nodes', []))->pluck('id')->all();

        return collect($groups)
            ->filter(fn ($group) => in_array($group['id'], $groupIds, true))
            ->flatMap(fn ($group) => collect($group['selling_plans'] ?? [])->pluck('id'))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Every plan in the groups, with its group's name and app, optionally limited to
     * the given plan ids (a variant's `selling_plan_ids`; null for all of them).
     */
    public static function plans(?array $groups, ?array $planIds = null): array
    {
        return collect($groups ?? [])
            ->flatMap(fn ($group) => collect($group['selling_plans'] ?? [])->map(fn ($plan) => array_merge($plan, [
                'group_name' => $group['name'] ?? null,
                'app_id' => $group['app_id'] ?? null,
            ])))
            ->when($planIds !== null, fn ($plans) => $plans->filter(fn ($plan) => in_array($plan['id'], $planIds, true)))
            ->values()
            ->all();
    }

    /**
     * The pricing policy for the first delivery (no after_cycle, or 0). Policies for
     * later cycles don't change the price shown.
     */
    public static function initialPolicy(array $plan): ?array
    {
        return collect($plan['pricing_policies'] ?? [])->first(fn ($policy) => ! ($policy['after_cycle'] ?? null));
    }

    /**
     * A price on a plan, or null when it can't be worked out: fixed amount and price
     * adjustments are in the shop's currency, so aren't applied to a price in another
     * currency (a market's). The cart's sellingPlanAllocation has the exact price.
     */
    public static function price($price, array $plan, bool $inShopCurrency = true): ?float
    {
        if (! is_numeric($price)) {
            return null;
        }

        $price = (float) $price;

        if (! $policy = static::initialPolicy($plan)) {
            return $price;
        }

        $value = (float) ($policy['adjustment_value'] ?? 0);

        return match ($policy['adjustment_type'] ?? null) {
            self::PERCENTAGE => max(0, round($price * (100 - $value) / 100, 2)),
            self::FIXED_AMOUNT => $inShopCurrency ? max(0, round($price - $value, 2)) : null,
            self::PRICE => $inShopCurrency ? $value : null,
            default => $price,
        };
    }
}
