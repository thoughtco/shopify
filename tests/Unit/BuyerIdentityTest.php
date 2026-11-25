<?php

namespace Thoughtco\Shopify\Tests\Unit;

use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Auth\User;
use Statamic\Facades;
use Thoughtco\Shopify\Clients\Graphql;
use Thoughtco\Shopify\Clients\HttpResponse;
use Thoughtco\Shopify\Tests\TestCase;

class BuyerIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('shopify.cart.buyer_identity', true);
    }

    private function user(array $data = []): User
    {
        return tap(Facades\User::make()->email('sam@example.com')->data($data))->saveQuietly();
    }

    private function customerResponse(array $customer): HttpResponse
    {
        return new HttpResponse(status: 200, body: json_encode(['data' => ['customer' => $customer]]));
    }

    private function customer(array $extra = []): array
    {
        return array_merge([
            'email' => 'sam@shopify.example.com',
            'phone' => '+447700900000',
            'defaultAddress' => [
                'firstName' => 'Sam',
                'lastName' => 'Shopper',
                'company' => null,
                'address1' => '1 High Street',
                'address2' => '',
                'city' => 'London',
                'province' => 'England',
                'country' => 'United Kingdom',
                'zip' => 'N1 1AA',
                'phone' => '+447700900001',
            ],
        ], $extra);
    }

    #[Test]
    public function is_not_found_unless_enabled()
    {
        config()->set('shopify.cart.buyer_identity', false);

        $this->mock(Graphql::class)->shouldNotReceive('query');

        $this->actingAs($this->user(['shopify_id' => 123]))
            ->getJson('/!/shopify/buyer')
            ->assertNotFound();
    }

    #[Test]
    public function is_empty_for_guests()
    {
        $this->mock(Graphql::class)->shouldNotReceive('query');

        $this->getJson('/!/shopify/buyer')
            ->assertNoContent()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    #[Test]
    public function is_empty_for_users_not_linked_to_a_customer()
    {
        $this->mock(Graphql::class)->shouldNotReceive('query');

        $this->actingAs($this->user())
            ->getJson('/!/shopify/buyer')
            ->assertNoContent();
    }

    #[Test]
    public function is_empty_when_shopify_has_no_customer()
    {
        $this->mock(Graphql::class)->shouldReceive('query')->once()->andReturn(
            new HttpResponse(status: 200, body: json_encode(['data' => ['customer' => null]]))
        );

        $this->actingAs($this->user(['shopify_id' => 123]))
            ->getJson('/!/shopify/buyer')
            ->assertNoContent();
    }

    #[Test]
    public function returns_the_linked_customers_buyer_identity()
    {
        $this->mock(Graphql::class)->shouldReceive('query')->once()
            ->withArgs(fn ($query) => $query['variables'] === ['id' => 'gid://shopify/Customer/123'])
            ->andReturn($this->customerResponse($this->customer()));

        $response = $this->actingAs($this->user(['shopify_id' => 123]))
            ->getJson('/!/shopify/buyer')
            ->assertOk()
            ->assertExactJson([
                'email' => 'sam@shopify.example.com',
                'phone' => '+447700900000',
                'deliveryAddressPreferences' => [[
                    'deliveryAddress' => [
                        'firstName' => 'Sam',
                        'lastName' => 'Shopper',
                        'address1' => '1 High Street',
                        'city' => 'London',
                        'province' => 'England',
                        'country' => 'United Kingdom',
                        'zip' => 'N1 1AA',
                        'phone' => '+447700900001',
                    ],
                ]],
            ]);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame('Cookie', $response->headers->get('Vary'));
    }

    #[Test]
    public function falls_back_to_the_users_email_and_leaves_out_missing_details()
    {
        $this->mock(Graphql::class)->shouldReceive('query')->once()
            ->andReturn($this->customerResponse(['email' => null, 'phone' => null, 'defaultAddress' => null]));

        $this->actingAs($this->user(['shopify_id' => 123]))
            ->getJson('/!/shopify/buyer')
            ->assertOk()
            ->assertExactJson(['email' => 'sam@example.com']);
    }

    #[Test]
    public function uses_the_customer_in_the_requested_store_on_a_multi_store_site()
    {
        config()->set('shopify.multi_store', [
            'enabled' => true,
            'mode' => 'unified',
            'primary_store' => 'uk',
            'stores' => [
                'uk' => ['url' => 'uk-store.myshopify.com', 'admin_token' => 'uk-token'],
                'us' => ['url' => 'us-store.myshopify.com', 'admin_token' => 'us-token'],
            ],
        ]);

        $this->mock(Graphql::class)->shouldNotReceive('query');
        $this->app->instance('shopify.graphql.uk', tap(Mockery::mock(Graphql::class))->shouldNotReceive('query'));
        $this->app->instance('shopify.graphql.us', tap(Mockery::mock(Graphql::class), function ($mock) {
            $mock->shouldReceive('query')->once()
                ->withArgs(fn ($query) => $query['variables'] === ['id' => 'gid://shopify/Customer/222'])
                ->andReturn($this->customerResponse($this->customer(['email' => 'sam@us.example.com'])));
        }));

        $user = $this->user(['shopify_ids' => ['us' => 222]]);

        $this->actingAs($user)
            ->getJson('/!/shopify/buyer?store=us')
            ->assertOk()
            ->assertJsonPath('email', 'sam@us.example.com');

        // not linked in that store, or no (valid) store: nothing to look up
        $this->actingAs($user)->getJson('/!/shopify/buyer?store=uk')->assertNoContent();
        $this->actingAs($user)->getJson('/!/shopify/buyer')->assertNoContent();
        $this->actingAs($user)->getJson('/!/shopify/buyer?store=not-a-store')->assertNoContent();
    }

    #[Test]
    public function tokens_point_the_cart_at_the_endpoint_without_any_personal_data()
    {
        $this->actingAs($this->user(['shopify_id' => 123]));

        $output = (string) Facades\Parse::template('{{ shopify:tokens }}', [], trusted: true);

        $this->assertStringContainsString('"buyerIdentity":true', $output);
        $this->assertStringContainsString('"buyerIdentityUrl":"/!/shopify/buyer"', $output);
        $this->assertStringNotContainsString('sam@example.com', $output);

        config()->set('shopify.cart.buyer_identity', false);

        $this->assertStringNotContainsString('buyerIdentity', (string) Facades\Parse::template('{{ shopify:tokens }}', [], trusted: true));
    }
}
