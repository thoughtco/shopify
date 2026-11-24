<?php

namespace Thoughtco\Shopify\Fieldtypes;

use Statamic\Fields\Fieldtype;
use Statamic\Support\Str;
use Thoughtco\Shopify\Support\SellingPlans as SellingPlanData;

/**
 * Read-only display of the selling plan groups imported onto a product.
 */
class SellingPlans extends Fieldtype
{
    protected $icon = 'repeat';

    protected $selectable = false;

    /**
     * Labels for each plan's delivery and pricing, keyed by plan id. The value itself
     * is left alone so it's saved back unchanged.
     */
    public function preload()
    {
        return [
            'labels' => collect(SellingPlanData::plans($this->field->value() ?? []))
                ->mapWithKeys(fn ($plan) => [$plan['id'] => [
                    'delivery' => static::deliveryLabel($plan),
                    'pricing' => static::pricingLabel($plan),
                ]])
                ->all(),
        ];
    }

    public static function deliveryLabel(array $plan): string
    {
        if (! ($plan['recurring_deliveries'] ?? false)) {
            return __('One delivery');
        }

        if (! $interval = $plan['interval'] ?? null) {
            return __('Recurring');
        }

        $count = (int) ($plan['interval_count'] ?? 1);
        $unit = Str::plural(strtolower($interval), $count);

        return $count === 1
            ? __('Every :unit', ['unit' => $unit])
            : __('Every :count :unit', ['count' => $count, 'unit' => $unit]);
    }

    public static function pricingLabel(array $plan): string
    {
        $labels = collect($plan['pricing_policies'] ?? [])
            ->map(function ($policy) {
                $value = (float) ($policy['adjustment_value'] ?? 0);

                $label = match ($policy['adjustment_type'] ?? null) {
                    SellingPlanData::PERCENTAGE => __(':value% off', ['value' => $value + 0]),
                    SellingPlanData::FIXED_AMOUNT => __(':value off', ['value' => number_format($value, 2)]),
                    SellingPlanData::PRICE => __('Price :value', ['value' => number_format($value, 2)]),
                    default => null,
                };

                if ($label && ($cycle = $policy['after_cycle'] ?? null)) {
                    $label .= ' '.__('after :count deliveries', ['count' => $cycle]);
                }

                return $label;
            })
            ->filter();

        return $labels->isNotEmpty() ? $labels->join(', ') : __('Full price');
    }
}
