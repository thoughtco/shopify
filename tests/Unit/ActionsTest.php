<?php

namespace Thoughtco\Shopify\Tests\Unit;

use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades;
use Statamic\Facades\User;
use Statamic\Support\Arr;
use Thoughtco\Shopify\Clients\Graphql;
use Thoughtco\Shopify\Clients\HttpResponse;
use Thoughtco\Shopify\Tests\TestCase;

class ActionsTest extends TestCase
{
    private function actingAsCustomer(int $shopifyId = 1)
    {
        $user = tap(User::make()->email('customer@example.com')->set('shopify_id', $shopifyId))->save();

        return $this->actingAs($user);
    }

    #[Test]
    public function gets_correct_data_from_action_url()
    {
        $product = Facades\Entry::make()
            ->data([
                'title' => 'Obi wan',
                'vendor' => 'Kenobe',
                'slug' => 'obi-wan',
                'product_id' => 1,
            ])
            ->collection(config('shopify.collection_handle', 'products'));

        $product->save();

        $variant = Facades\Entry::make()
            ->data([
                'title' => 'T-shirt',
                'slug' => 'obi-wan-tshirt',
                'sku' => 'obi-wan-tshirt',
                'product_slug' => 'obi-wan',
                'price' => 9.99,
                'inventory_quantity' => 10,
                'variant_id' => 'abc',
                'option1' => 'a',
            ])
            ->collection('variants');

        $variant->save();

        $variant2 = Facades\Entry::make()
            ->data([
                'title' => 'Another T-shirt',
                'slug' => 'obi-wan-tshirt-2',
                'sku' => 'obi-wan-tshirt-2',
                'product_slug' => 'obi-wan',
                'price' => 10.99,
                'inventory_quantity' => 5,
                'variant_id' => 'def',
                'option1' => 'b',
            ])
            ->collection('variants');

        $variant2->save();

        $response = $this->get('/!/shopify/variants/obi-wan');
        $this->assertSame('[{"title":"T-shirt","id":"abc","price":9.99,"inventory_quantity":10},{"title":"Another T-shirt","id":"def","price":10.99,"inventory_quantity":5}]', $response->getContent());

        $response = $this->get('/!/shopify/variants/obi-wan?option1=a');
        $this->assertSame('[{"title":"T-shirt","id":"abc","price":9.99,"inventory_quantity":10}]', $response->getContent());
    }

    #[Test]
    public function address_endpoints_require_authentication()
    {
        $this->postJson('/!/shopify/address', ['firstName' => 'a', 'lastName' => 'b', 'address1' => 'c', 'city' => 'd', 'zip' => 'f', 'countryCode' => 'GB'])
            ->assertStatus(401);

        $this->postJson('/!/shopify/address/1', ['firstName' => 'a', 'lastName' => 'b', 'address1' => 'c', 'city' => 'd', 'zip' => 'f', 'countryCode' => 'GB'])
            ->assertStatus(401);

        $this->postJson('/!/shopify/address/1', ['_method' => 'delete'])
            ->assertStatus(401);
    }

    #[Test]
    public function address_is_scoped_to_the_logged_in_user_not_a_supplied_customer_id()
    {
        $captured = null;

        $this->mock(Graphql::class, function (MockInterface $mock) use (&$captured) {
            $mock
                ->shouldReceive('query')
                ->andReturnUsing(function ($args) use (&$captured) {
                    $captured = $args;

                    return new HttpResponse(status: 200, body: '{"data":{"customerAddressCreate":{"address":{"id":"gid://shopify/MailingAddress/1"},"userErrors":[]}}}');
                });
        });

        $this->actingAsCustomer(555)
            ->postJson('/!/shopify/address', ['customer_id' => 999, 'firstName' => 'a', 'lastName' => 'b', 'address1' => 'c', 'city' => 'd', 'zip' => 'f', 'countryCode' => 'GB'])
            ->assertStatus(200);

        $this->assertSame('gid://shopify/Customer/555', $captured['variables']['customerId']);
    }

    #[Test]
    public function creates_an_address()
    {
        $this->actingAsCustomer();

        $this->mock(Graphql::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('query')
                ->andReturn(new HttpResponse(
                    status: 200,
                    body: '{
                      "data": {
                        "customerAddressCreate": {
                          "address": {
                            "id": "gid://shopify/MailingAddress/1?model_name=CustomerAddress",
                            "firstName": "First",
                            "lastName": "Name",
                            "company": "Company",
                            "address1": "Line 1",
                            "address2": "Line 2",
                            "city": "City",
                            "province": "Province",
                            "country": "Country",
                            "zip": "Zip",
                            "phone": "Phone",
                            "name": "Name"
                          },
                          "userErrors": []
                        }
                      }
                  }'
                ));
        });

        $response = $this->postJson('/!/shopify/address', []);
        $response->assertStatus(422);

        $response = $this->postJson('/!/shopify/address', ['firstName' => 'a', 'lastName' => 'b', 'address1' => 'c', 'city' => 'd', 'zip' => 'f', 'countryCode' => 'GB']);
        $response->assertStatus(200);
    }

    #[Test]
    public function updates_an_address()
    {
        $this->actingAsCustomer();

        $this->mock(Graphql::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('query')
                ->andReturn(new HttpResponse(
                    status: 200,
                    body: '{
                      "data": {
                        "customerAddressUpdate": {
                          "address": {
                            "id": "gid://shopify/MailingAddress/1?model_name=CustomerAddress",
                            "firstName": "First",
                            "lastName": "Name",
                            "company": "Company",
                            "address1": "Line 1",
                            "address2": "Line 2",
                            "city": "City",
                            "province": "Province",
                            "country": "Country",
                            "zip": "Zip",
                            "phone": "Phone",
                            "name": "Name"
                          },
                          "userErrors": []
                        }
                      }
                  }'
                ));
        });

        $response = $this->postJson('/!/shopify/address/1', []);
        $response->assertStatus(422);

        $response = $this->postJson('/!/shopify/address/1', ['firstName' => 'a', 'lastName' => 'b', 'address1' => 'c', 'city' => 'd', 'zip' => 'f', 'countryCode' => 'GB']);
        $response->assertStatus(200);
    }

    #[Test]
    public function deletes_an_address()
    {
        $this->actingAsCustomer();

        $this->mock(Graphql::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('query')
                ->andReturn(new HttpResponse(
                    status: 200,
                    body: '{
                      "data": {
                        "customerAddressDelete": {
                          "deletedAddressId":  "gid://shopify/MailingAddress/1?model_name=CustomerAddress",
                          "userErrors": []
                        }
                      }
                  }'
                ));
        });

        $response = $this->postJson('/!/shopify/address/1', ['_method' => 'delete']);
        $response->assertStatus(200);
    }

    #[Test]
    public function can_use_precognition_when_creating_an_address()
    {
        $response = $this
            ->actingAsCustomer()
            ->withPrecognition()
            ->postJson('/!/shopify/address', []);

        $this->assertSame('true', $response->headers->get('precognition'));
        $response->assertStatus(422);
    }

    #[Test]
    public function can_use_precognition_when_updating_an_address()
    {
        $response = $this
            ->actingAsCustomer()
            ->withPrecognition()
            ->postJson('/!/shopify/address/1', []);

        $this->assertSame('true', $response->headers->get('precognition'));
        $response->assertStatus(422);
    }

    #[Test]
    public function sends_default_as_set_as_default_and_strips_fields_not_in_mailing_address_input()
    {
        $captured = null;

        $this->mock(Graphql::class, function (MockInterface $mock) use (&$captured) {
            $mock
                ->shouldReceive('query')
                ->andReturnUsing(function ($args) use (&$captured) {
                    $captured = $args;

                    return new HttpResponse(status: 200, body: '{"data":{"customerAddressCreate":{"address":{"id":"gid://shopify/MailingAddress/1"},"userErrors":[]}}}');
                });
        });

        $this->actingAsCustomer()
            ->postJson('/!/shopify/address', ['firstName' => 'a', 'lastName' => 'b', 'address1' => 'c', 'city' => 'd', 'provinceCode' => 'ENG', 'zip' => 'f', 'countryCode' => 'GB', 'name' => 'a b', 'default' => true])
            ->assertStatus(200);

        $this->assertTrue($captured['variables']['setAsDefault']);
        $this->assertArrayNotHasKey('default', $captured['variables']['address']);
        $this->assertArrayNotHasKey('name', $captured['variables']['address']);
        $this->assertSame('GB', $captured['variables']['address']['countryCode']);
    }

    #[Test]
    public function returns_shopify_user_errors_when_creating_an_address()
    {
        $this->mock(Graphql::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('query')
                ->andReturn(new HttpResponse(status: 200, body: '{"data":{"customerAddressCreate":{"address":null,"userErrors":[{"field":["address","zip"],"message":"Zip is invalid"}]}}}'));
        });

        $this->actingAsCustomer()
            ->postJson('/!/shopify/address', ['firstName' => 'a', 'lastName' => 'b', 'address1' => 'c', 'city' => 'd', 'zip' => 'f', 'countryCode' => 'GB'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Zip is invalid']);
    }

    #[Test]
    public function sends_country_and_province_codes_and_accepts_codes_in_the_old_fields()
    {
        $captured = [];

        $this->mock(Graphql::class, function (MockInterface $mock) use (&$captured) {
            $mock->shouldReceive('query')
                ->andReturnUsing(function ($args) use (&$captured) {
                    $captured[] = $args['variables']['address'];

                    return new HttpResponse(status: 200, body: '{"data":{"customerAddressCreate":{"address":{"id":"gid://shopify/MailingAddress/1"},"userErrors":[]}}}');
                });
        });

        $address = ['firstName' => 'a', 'lastName' => 'b', 'address1' => 'c', 'city' => 'd', 'zip' => 'f'];

        $this->actingAsCustomer()->postJson('/!/shopify/address', $address + ['countryCode' => 'us', 'provinceCode' => 'ca'])->assertOk();
        $this->actingAsCustomer()->postJson('/!/shopify/address', $address + ['country' => 'GB', 'province' => 'ENG'])->assertOk();
        $this->actingAsCustomer()->postJson('/!/shopify/address', $address + ['countryCode' => 'GB', 'province' => 'Kent'])->assertOk();

        $this->assertSame(['countryCode' => 'US', 'provinceCode' => 'CA'], Arr::only($captured[0], ['countryCode', 'provinceCode', 'country', 'province']));
        $this->assertSame(['countryCode' => 'GB', 'provinceCode' => 'ENG'], Arr::only($captured[1], ['countryCode', 'provinceCode', 'country', 'province']));

        // a province name isn't a code, and MailingAddressInput has no province field
        $this->assertSame(['countryCode' => 'GB'], Arr::only($captured[2], ['countryCode', 'provinceCode', 'country', 'province']));
    }

    #[Test]
    public function needs_a_country_code_rather_than_a_name()
    {
        $this->mock(Graphql::class)->shouldNotReceive('query');

        $this->actingAsCustomer()
            ->postJson('/!/shopify/address', ['firstName' => 'a', 'lastName' => 'b', 'address1' => 'c', 'city' => 'd', 'zip' => 'f', 'country' => 'Ireland'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['countryCode' => 'country']);
    }

    #[Test]
    public function marketing_consent_requires_authentication()
    {
        $this->postJson('/!/shopify/marketing', ['email' => 1])->assertUnauthorized();
    }

    #[Test]
    public function updates_the_logged_in_customers_marketing_consent()
    {
        $captured = [];

        $this->mock(Graphql::class, function (MockInterface $mock) use (&$captured) {
            $mock->shouldReceive('query')
                ->andReturnUsing(function ($args) use (&$captured) {
                    $captured[] = $args;
                    $operation = str_contains($args['query'], 'customerSmsMarketingConsentUpdate') ? 'customerSmsMarketingConsentUpdate' : 'customerEmailMarketingConsentUpdate';
                    $field = $operation === 'customerSmsMarketingConsentUpdate' ? 'smsMarketingConsent' : 'emailMarketingConsent';
                    $state = $args['variables']['input'][$field]['marketingState'];

                    return new HttpResponse(status: 200, body: json_encode(['data' => [$operation => ['customer' => [$field => ['marketingState' => $state]], 'userErrors' => []]]]));
                });
        });

        // a customer_id in the request is ignored: always the logged in user's customer
        $this->actingAsCustomer(5)
            ->postJson('/!/shopify/marketing', ['email' => '1', 'sms' => '0', 'customer_id' => 999])
            ->assertOk()
            ->assertJson(['email_marketing_state' => 'SUBSCRIBED', 'sms_marketing_state' => 'UNSUBSCRIBED']);

        $this->assertCount(2, $captured);
        $this->assertSame('gid://shopify/Customer/5', $captured[0]['variables']['input']['customerId']);
        $this->assertSame('SUBSCRIBED', $captured[0]['variables']['input']['emailMarketingConsent']['marketingState']);
        $this->assertSame('SINGLE_OPT_IN', $captured[0]['variables']['input']['emailMarketingConsent']['marketingOptInLevel']);
        $this->assertSame('UNSUBSCRIBED', $captured[1]['variables']['input']['smsMarketingConsent']['marketingState']);

        // only the channels sent are changed
        $captured = [];
        $this->actingAsCustomer(5)->postJson('/!/shopify/marketing', ['email' => '0'])->assertOk();
        $this->assertCount(1, $captured);

        // nothing to change, or not a boolean
        $this->actingAsCustomer(5)->postJson('/!/shopify/marketing', [])->assertStatus(422);
        $this->actingAsCustomer(5)->postJson('/!/shopify/marketing', ['email' => 'yes please'])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    #[Test]
    public function returns_shopify_user_errors_when_updating_marketing_consent()
    {
        $this->mock(Graphql::class, function (MockInterface $mock) {
            $mock->shouldReceive('query')
                ->andReturn(new HttpResponse(status: 200, body: '{"data":{"customerSmsMarketingConsentUpdate":{"customer":null,"userErrors":[{"field":["input","smsMarketingConsent"],"message":"Phone number is required"}]}}}'));
        });

        $this->actingAsCustomer()
            ->postJson('/!/shopify/marketing', ['sms' => '1'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Phone number is required']);
    }
}
