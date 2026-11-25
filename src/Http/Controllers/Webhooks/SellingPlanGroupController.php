<?php

namespace Thoughtco\Shopify\Http\Controllers\Webhooks;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Statamic\Facades\Entry;
use Statamic\Support\Arr;
use Thoughtco\Shopify\Activity\Activity;
use Thoughtco\Shopify\Activity\ActivityLog;
use Thoughtco\Shopify\Activity\ActivityStatus;
use Thoughtco\Shopify\Events;

/**
 * A selling plan group was created, changed or deleted by the merchant's subscription
 * app: re-import the products it applies to, and the imported products that had it
 * (they may have been removed from the group, or it was deleted).
 */
class SellingPlanGroupController extends WebhooksController
{
    public function create(Request $request)
    {
        return $this->processWebhook($request, 'selling_plan_groups/create', fn ($data) => Events\SellingPlanGroupCreate::dispatch($data));
    }

    public function update(Request $request)
    {
        return $this->processWebhook($request, 'selling_plan_groups/update', fn ($data) => Events\SellingPlanGroupUpdate::dispatch($data));
    }

    public function delete(Request $request)
    {
        return $this->processWebhook($request, 'selling_plan_groups/delete', fn ($data) => Events\SellingPlanGroupDelete::dispatch($data));
    }

    private function processWebhook(Request $request, string $topic, Closure $eventCallback)
    {
        $data = json_decode($request->getContent());

        if (! is_object($data)) {
            $this->recordOutcome($request, ActivityStatus::Skipped, ['message' => 'No selling plan group in the payload']);

            return response()->json([], 200);
        }

        $storeHandle = $this->storeHandle($request);
        $groupId = data_get($data, 'admin_graphql_api_id') ?? (data_get($data, 'id') ? 'gid://shopify/SellingPlanGroup/'.data_get($data, 'id') : null);

        // Shopify can send the same change more than once (separate deliveries, so not
        // caught by the webhook id): only re-import for the first of identical payloads
        if (! Cache::add('shopify.selling_plan_group.'.$topic.'.'.$storeHandle.'.'.md5($request->getContent()), true, now()->addMinutes(5))) {
            $this->recordOutcome($request, ActivityStatus::Skipped, array_filter([
                'resource_title' => data_get($data, 'name'),
                'message' => 'The same change was received moments ago',
            ]));

            return response()->json([], 200);
        }

        $products = $this->importedProductsWithGroup($groupId, $storeHandle)
            ->union($this->productsInPayload($data));

        foreach ($products as $productId => $title) {
            ActivityLog::queueProductImport($productId, $storeHandle, Activity::SOURCE_WEBHOOK, attributes: [
                'topic' => $topic,
                'parent_id' => $this->activityId($request),
                'resource_title' => $title,
            ]);
        }

        $this->recordOutcome($request, $products->isNotEmpty() ? ActivityStatus::Updated : ActivityStatus::Skipped, array_filter([
            'resource_title' => data_get($data, 'name'),
            'message' => $products->isNotEmpty()
                ? "Queued {$products->count()} product update(s) for selling plans"
                : 'No products use this selling plan group',
        ]));

        $eventCallback($data);

        return response()->json([
            'message' => 'Products have been dispatched to the queue for update',
        ], 200);
    }

    /**
     * The products the group applies to: directly, or through one of their variants.
     *
     * @return Collection<int, ?string> titles keyed by product id
     */
    private function productsInPayload(object $data): Collection
    {
        return collect(data_get($data, 'products', []))
            ->map(fn ($product) => is_object($product) ? data_get($product, 'id') : $product)
            ->merge(collect(data_get($data, 'product_variants', []))->map(fn ($variant) => is_object($variant) ? data_get($variant, 'product_id') : null))
            ->merge(data_get($data, 'product_ids', []))
            ->map(fn ($id) => (int) (is_string($id) ? last(explode('/', $id)) : $id))
            ->filter()
            ->unique()
            ->mapWithKeys(fn ($id) => [$id => null]);
    }

    /**
     * Imported products that have the group, from this store.
     *
     * @return Collection<int, ?string> titles keyed by product id
     */
    private function importedProductsWithGroup(?string $groupId, ?string $storeHandle): Collection
    {
        if (! $groupId) {
            return collect();
        }

        $query = fn (string $field) => Entry::query()
            ->where('collection', config('shopify.collection_handle', 'products'))
            ->whereNotNull($field)
            ->get();

        return $query('selling_plan_groups')
            ->merge($storeHandle ? $query('multi_store_selling_plans') : [])
            ->unique(fn ($entry) => $entry->id())
            ->filter(function ($entry) use ($groupId, $storeHandle) {
                $groups = array_merge(
                    $entry->get('selling_plan_groups') ?? [],
                    $storeHandle ? Arr::get($entry->get('multi_store_selling_plans') ?? [], $storeHandle.'.selling_plan_groups', []) : [],
                );

                return collect($groups)->contains(fn ($group) => ($group['id'] ?? null) === $groupId);
            })
            ->filter(fn ($entry) => $entry->get('product_id'))
            ->mapWithKeys(fn ($entry) => [(int) $entry->get('product_id') => $entry->get('title')]);
    }
}
