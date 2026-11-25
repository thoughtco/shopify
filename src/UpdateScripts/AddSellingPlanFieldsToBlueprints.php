<?php

namespace Thoughtco\Shopify\UpdateScripts;

use Statamic\Facades\Collection;
use Statamic\Facades\YAML;
use Statamic\Support\Arr;
use Thoughtco\Shopify\Support\SellingPlans;

/**
 * Add the selling plan fields to existing product blueprints (in the "Details" tab's
 * Subscriptions section) and variant blueprints, skipping fields a blueprint already has.
 */
class AddSellingPlanFieldsToBlueprints extends AddSyncFieldsToProductBlueprint
{
    public const FIELDS = SellingPlans::PRODUCT_FIELDS;

    public function shouldUpdate($newVersion, $oldVersion)
    {
        return $this->isUpdatingTo('9.2.0');
    }

    public function update()
    {
        parent::update();

        if (! $collection = Collection::find('variants')) {
            return;
        }

        $fields = collect(Arr::get(YAML::file(__DIR__.'/../../resources/blueprints/variant.yaml')->parse(), 'sections.main.fields', []))
            ->filter(fn ($field) => in_array($field['handle'], SellingPlans::VARIANT_FIELDS))
            ->values();

        $collection->entryBlueprints()->each(function ($blueprint) use ($fields) {
            $missing = $fields->reject(fn ($field) => $blueprint->hasField($field['handle']))->values()->all();

            if (! $missing) {
                return;
            }

            $contents = $blueprint->contents();
            $tab = array_key_first(Arr::get($contents, 'tabs', [])) ?? 'main';

            Arr::set($contents, "tabs.{$tab}.sections.0.fields", array_merge(Arr::get($contents, "tabs.{$tab}.sections.0.fields", []), $missing));

            $blueprint->setContents($contents)->save();
        });
    }

    protected function message(): string
    {
        return 'Shopify selling plan fields have been added to your product and variant blueprints.';
    }
}
