<?php

namespace Thoughtco\Shopify\Listeners;

use Statamic\Events\EntryBlueprintFound;
use Statamic\Fields\Blueprint;
use Statamic\Support\Arr;
use Thoughtco\Shopify\Support\SellingPlans;

/**
 * Shows the imported selling plans, read only, when `selling_plans` is enabled: a
 * "Subscriptions" section in the product blueprint's Details tab, and the variants'
 * plan ids. Added at runtime rather than written to the blueprints, so sites without
 * selling plans never see them and no update script is needed.
 */
class AddSellingPlanFieldsToBlueprints
{
    public function handle(EntryBlueprintFound $event): void
    {
        if (! SellingPlans::enabled() || ! $event->blueprint instanceof Blueprint) {
            return;
        }

        // the blueprint editor loads blueprints through the same event: adding the
        // section there would show it in the editor and save it into the blueprint
        if (request()->routeIs('statamic.cp.blueprints.*')) {
            return;
        }

        match ($event->blueprint->namespace()) {
            'collections.'.config('shopify.collection_handle', 'products') => $this->addProductFields($event->blueprint),
            'collections.variants' => $event->blueprint->ensureField('selling_plan_ids', [
                'type' => 'hidden',
                'display' => __('Selling plans'),
                'listable' => 'hidden',
            ]),
            default => null,
        };
    }

    private function addProductFields(Blueprint $blueprint): void
    {
        if ($blueprint->hasField('requires_selling_plan') || $blueprint->hasField('selling_plan_groups')) {
            return;
        }

        $contents = $blueprint->contents();
        $tab = isset($contents['tabs']['shopify']) ? 'shopify' : (array_key_first($contents['tabs'] ?? []) ?? 'main');
        $sections = Arr::get($contents, "tabs.{$tab}.sections", []);

        // before the "Shopify record" section when it's there (the last one in the default blueprint)
        $position = collect($sections)->search(fn ($section) => ($section['display'] ?? null) === 'Shopify record');
        array_splice($sections, $position === false ? count($sections) : $position, 0, [$this->section()]);

        Arr::set($contents, "tabs.{$tab}.sections", $sections);

        $blueprint->setContents($contents);
    }

    private function section(): array
    {
        return [
            'display' => __('Subscriptions'),
            'instructions' => __('Selling plans from your subscription app'),
            'fields' => [
                [
                    'handle' => 'requires_selling_plan',
                    'field' => [
                        'type' => 'toggle',
                        'display' => __('Subscription only'),
                        'instructions' => __('Can only be bought on a selling plan'),
                        'listable' => 'hidden',
                        'visibility' => 'read_only',
                    ],
                ],
                [
                    'handle' => 'selling_plan_groups',
                    'field' => [
                        'type' => 'selling_plans',
                        'display' => __('Selling plans'),
                        'listable' => 'hidden',
                        'visibility' => 'read_only',
                    ],
                ],
            ],
        ];
    }
}
