<?php

namespace Thoughtco\Shopify\Http\Controllers\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\User;
use Statamic\Http\Controllers\Controller;
use Statamic\Support\Arr;
use Thoughtco\Shopify\Support\Customers;
use Thoughtco\Shopify\Support\StoreConfig;

/**
 * The logged in user's details for the Storefront cart's buyerIdentity, so Shopify's
 * checkout is pre-filled. Personal data, so it's never part of a (statically cached)
 * page: the cart JS fetches it, and the response is never cached.
 */
class BuyerIdentityController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless(config('shopify.cart.buyer_identity'), 404);

        if (! $user = User::current()) {
            return $this->empty();
        }

        $store = Customers::store($request->query('store'));

        if (! $customerId = Customers::id($user, $store)) {
            return $this->empty();
        }

        $response = StoreConfig::clientFor($store)->query([
            'query' => <<<'QUERY'
                query customer($id: ID!) {
                  customer(id: $id) {
                    email
                    phone
                    defaultAddress {
                      firstName
                      lastName
                      company
                      address1
                      address2
                      city
                      provinceCode
                      countryCodeV2
                      zip
                      phone
                    }
                  }
                }
                QUERY,
            'variables' => ['id' => 'gid://shopify/Customer/'.$customerId],
        ]);

        if (! $customer = Arr::get($response->getDecodedBody() ?? [], 'data.customer')) {
            if ($message = $response->firstError('customer')) {
                Log::warning('Shopify: could not fetch the buyer identity: '.$message);
            }

            return $this->empty();
        }

        return $this->private(response()->json(array_filter([
            'email' => ($customer['email'] ?? null) ?: $user->email(),
            'phone' => $customer['phone'] ?? null,
            'deliveryAddress' => $this->address($customer['defaultAddress'] ?? null),
        ])));
    }

    /**
     * The Admin API address as a Storefront CartDeliveryAddressInput, without empty fields.
     */
    private function address(?array $address): ?array
    {
        if (! $address) {
            return null;
        }

        $address['countryCode'] = $address['countryCodeV2'] ?? null;

        return array_filter(Arr::only($address, [
            'firstName', 'lastName', 'company', 'address1', 'address2', 'city', 'provinceCode', 'countryCode', 'zip', 'phone',
        ]), fn ($value) => filled($value)) ?: null;
    }

    private function empty()
    {
        return $this->private(response()->noContent());
    }

    private function private($response)
    {
        return $response->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Vary' => 'Cookie',
        ]);
    }
}
