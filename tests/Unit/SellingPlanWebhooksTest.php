<?php

namespace Thoughtco\Shopify\Tests\Unit;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades;
use Thoughtco\Shopify\Contracts\ActivityRepository;
use Thoughtco\Shopify\Enums\WebhookTopic;
use Thoughtco\Shopify\Events;
use Thoughtco\Shopify\Jobs\ImportSingleProductJob;
use Thoughtco\Shopify\Tests\TestCase;

class SellingPlanWebhooksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('shopify.ignore_webhook_integrity_check', true);
    }

    /**
     * The webhook delivery's activity (the queued product imports are its children).
     */
    private function delivery()
    {
        return collect(app(ActivityRepository::class)->paginate([], 50)->items())->firstWhere('parentId', null);
    }

    private function queuedProductIds(): array
    {
        $ids = [];

        Bus::assertDispatched(ImportSingleProductJob::class, function ($job) use (&$ids) {
            $ids[] = $job->productId;

            return true;
        });

        sort($ids);

        return $ids;
    }

    #[Test]
    public function selling_plan_topics_are_only_registered_when_selling_plans_are_imported()
    {
        $this->assertSame('selling_plan_groups/update', WebhookTopic::SellingPlanGroupsUpdate->topic());
        $this->assertNotContains(WebhookTopic::SellingPlanGroupsCreate, WebhookTopic::registered());
        $this->assertCount(count(WebhookTopic::cases()) - 3, WebhookTopic::registered());

        config()->set('shopify.selling_plans', true);

        $this->assertSame(WebhookTopic::cases(), WebhookTopic::registered());
        $this->assertStringEndsWith('/!/shopify/webhook/selling-plan-group/delete', WebhookTopic::SellingPlanGroupsDelete->callbackUrl());
    }

    #[Test]
    public function an_identical_delivery_moments_later_is_skipped()
    {
        Bus::fake();
        Event::fake([Events\SellingPlanGroupUpdate::class]);

        $payload = [
            'admin_graphql_api_id' => 'gid://shopify/SellingPlanGroup/7',
            'id' => 7,
            'name' => 'Subscribe & save',
            'products' => [['id' => 30]],
        ];

        // separate deliveries (different webhook ids) of the same change
        $this->postJson('/!/shopify/webhook/selling-plan-group/update', $payload, ['X-Shopify-Webhook-Id' => 'a'])->assertOk();
        $this->postJson('/!/shopify/webhook/selling-plan-group/update', $payload, ['X-Shopify-Webhook-Id' => 'b'])->assertOk();

        Bus::assertDispatchedTimes(ImportSingleProductJob::class, 1);
        Event::assertDispatchedTimes(Events\SellingPlanGroupUpdate::class, 1);

        // a different change is handled
        $this->postJson('/!/shopify/webhook/selling-plan-group/update', [...$payload, 'name' => 'Renamed'], ['X-Shopify-Webhook-Id' => 'c'])->assertOk();

        Bus::assertDispatchedTimes(ImportSingleProductJob::class, 2);
    }

    #[Test]
    public function an_updated_group_re_imports_its_products_and_the_products_that_had_it()
    {
        Bus::fake();
        Event::fake([Events\SellingPlanGroupUpdate::class]);

        Facades\Entry::make()->collection('products')->slug('old')->data([
            'title' => 'Old subscription',
            'product_id' => '30',
            'selling_plan_groups' => [['id' => 'gid://shopify/SellingPlanGroup/7', 'selling_plans' => []]],
        ])->save();

        Facades\Entry::make()->collection('products')->slug('other')->data([
            'title' => 'Other group',
            'product_id' => '40',
            'selling_plan_groups' => [['id' => 'gid://shopify/SellingPlanGroup/8', 'selling_plans' => []]],
        ])->save();

        $this->postJson('/!/shopify/webhook/selling-plan-group/update', [
            'admin_graphql_api_id' => 'gid://shopify/SellingPlanGroup/7',
            'id' => 7,
            'name' => 'Subscribe & save',
            'products' => [['id' => 10, 'admin_graphql_api_id' => 'gid://shopify/Product/10']],
            'product_variants' => [['id' => 100, 'product_id' => 20], ['id' => 101, 'product_id' => 10]],
        ], ['X-Shopify-Topic' => 'selling_plan_groups/update'])->assertOk();

        $this->assertSame([10, 20, 30], $this->queuedProductIds());

        $activity = $this->delivery();
        $this->assertSame('selling_plan_groups/update', $activity->topic);
        $this->assertSame('Subscribe & save', $activity->resourceTitle);
        $this->assertSame('Queued 3 product update(s) for selling plans', $activity->message);
        $this->assertSame(
            ['10' => null, '20' => null, '30' => 'Old subscription'],
            collect(app(ActivityRepository::class)->paginate([], 50)->items())
                ->where('parentId', $activity->id)
                ->mapWithKeys(fn ($child) => [$child->resourceId => $child->resourceTitle])
                ->sortKeys()
                ->all(),
            'Each product gets its own activity, titled when it has been imported'
        );

        Event::assertDispatched(Events\SellingPlanGroupUpdate::class, fn ($event) => $event->data->id === 7);
    }

    #[Test]
    public function a_deleted_group_re_imports_the_products_that_had_it()
    {
        Bus::fake();

        Facades\Entry::make()->collection('products')->slug('coffee')->data([
            'title' => 'Coffee',
            'product_id' => '30',
            'selling_plan_groups' => [['id' => 'gid://shopify/SellingPlanGroup/7', 'selling_plans' => []]],
        ])->save();

        // the delete payload only has the group's id
        $this->postJson('/!/shopify/webhook/selling-plan-group/delete', ['id' => 7], ['X-Shopify-Topic' => 'selling_plan_groups/delete'])
            ->assertOk();

        $this->assertSame([30], $this->queuedProductIds());
        $this->assertSame('updated', $this->delivery()->status->value);
    }

    #[Test]
    public function a_group_without_products_is_skipped()
    {
        Bus::fake();

        $this->postJson('/!/shopify/webhook/selling-plan-group/create', ['id' => 7, 'products' => []], ['X-Shopify-Topic' => 'selling_plan_groups/create'])
            ->assertOk();

        Bus::assertNotDispatched(ImportSingleProductJob::class);

        $activity = $this->delivery();
        $this->assertSame('skipped', $activity->status->value);
        $this->assertSame('No products use this selling plan group', $activity->message);
    }

    #[Test]
    public function multi_store_sites_re_import_for_the_store_that_sent_it()
    {
        Bus::fake();

        config()->set('shopify.multi_store', [
            'enabled' => true,
            'mode' => 'unified',
            'primary_store' => 'uk',
            'stores' => [
                'uk' => ['url' => 'uk-store.myshopify.com', 'admin_token' => 'uk', 'webhook_secret' => 'uk'],
                'us' => ['url' => 'us-store.myshopify.com', 'admin_token' => 'us', 'webhook_secret' => 'us'],
            ],
        ]);

        Facades\Entry::make()->collection('products')->slug('coffee')->data([
            'title' => 'Coffee',
            'product_id' => '30',
            'multi_store_selling_plans' => ['us' => ['selling_plan_groups' => [['id' => 'gid://shopify/SellingPlanGroup/7']]]],
        ])->save();

        $this->postJson('/!/shopify/webhook/selling-plan-group/delete', ['id' => 7], [
            'X-Shopify-Topic' => 'selling_plan_groups/delete',
            'X-Shopify-Shop-Domain' => 'us-store.myshopify.com',
        ])->assertOk();

        Bus::assertDispatched(ImportSingleProductJob::class, fn ($job) => $job->productId === 30 && $job->storeHandle === 'us');
    }
}
