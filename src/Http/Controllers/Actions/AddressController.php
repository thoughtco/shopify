<?php

namespace Thoughtco\Shopify\Http\Controllers\Actions;

use Illuminate\Http\Request;
use Statamic\Support\Arr;
use Statamic\Support\Str;
use Thoughtco\Shopify\Http\Requests;

class AddressController extends BaseActionController
{
    private const ADDRESS_FIELDS = <<<'FIELDS'
        id
        firstName
        lastName
        company
        address1
        address2
        city
        province
        country
        zip
        phone
        name
        FIELDS;

    public function create(Requests\CreateOrUpdateAddressRequest $request)
    {
        if (! $customerId = $this->customerId($request)) {
            return $this->withErrors($request, __('No Shopify customer is associated with the current user'));
        }

        $fields = self::ADDRESS_FIELDS;

        try {
            $address = $this->mutate($request, 'customerAddressCreate', <<<QUERY
                mutation customerAddressCreate(\$address: MailingAddressInput!, \$customerId: ID!, \$setAsDefault: Boolean) {
                  customerAddressCreate(address: \$address, customerId: \$customerId, setAsDefault: \$setAsDefault) {
                    address {
                      {$fields}
                    }
                    userErrors {
                      field
                      message
                    }
                  }
                }
                QUERY, [
                'address' => $this->addressInput($request->validated()),
                'customerId' => 'gid://shopify/Customer/'.$customerId,
                'setAsDefault' => (bool) $request->validated('default', false),
            ], 'address');

            return $this->withSuccess($request, [
                'message' => __('Address created'),
                'address' => $this->formatAddress($address),
            ]);
        } catch (\Exception $error) {
            return $this->withErrors($request, $error->getMessage());
        }
    }

    public function destroy(Request $request, $id)
    {
        if (! $customerId = $this->customerId($request)) {
            return $this->withErrors($request, __('No Shopify customer is associated with the current user'));
        }

        try {
            $this->mutate($request, 'customerAddressDelete', <<<'QUERY'
                mutation customerAddressDelete($addressId: ID!, $customerId: ID!) {
                  customerAddressDelete(addressId: $addressId, customerId: $customerId) {
                    deletedAddressId
                    userErrors {
                      field
                      message
                    }
                  }
                }
                QUERY, [
                'customerId' => 'gid://shopify/Customer/'.$customerId,
                'addressId' => $this->addressGid($id),
            ], 'deletedAddressId');

            return $this->withSuccess($request, [
                'message' => __('Address deleted'),
            ]);
        } catch (\Exception $error) {
            return $this->withErrors($request, $error->getMessage());
        }
    }

    public function store(Requests\CreateOrUpdateAddressRequest $request, $id)
    {
        if (! $customerId = $this->customerId($request)) {
            return $this->withErrors($request, __('No Shopify customer is associated with the current user'));
        }

        $fields = self::ADDRESS_FIELDS;

        try {
            $address = $this->mutate($request, 'customerAddressUpdate', <<<QUERY
                mutation customerAddressUpdate(\$address: MailingAddressInput!, \$addressId: ID!, \$customerId: ID!, \$setAsDefault: Boolean) {
                  customerAddressUpdate(address: \$address, addressId: \$addressId, customerId: \$customerId, setAsDefault: \$setAsDefault) {
                    address {
                      {$fields}
                    }
                    userErrors {
                      field
                      message
                    }
                  }
                }
                QUERY, [
                'address' => $this->addressInput($request->validated()),
                'addressId' => $this->addressGid($id),
                'customerId' => 'gid://shopify/Customer/'.$customerId,
                'setAsDefault' => (bool) $request->validated('default', false),
            ], 'address');

            return $this->withSuccess($request, [
                'message' => __('Address updated'),
                'address' => $this->formatAddress($address),
            ]);
        } catch (\Exception $error) {
            return $this->withErrors($request, $error->getMessage());
        }
    }

    /**
     * MailingAddressInput has no `default` or `name` field; `default` is sent
     * separately as setAsDefault.
     */
    private function addressInput(array $validated): array
    {
        return Arr::except($validated, ['default', 'name']);
    }

    private function addressGid($id): string
    {
        return 'gid://shopify/MailingAddress/'.$id.'?model_name=CustomerAddress';
    }

    private function formatAddress(array $address): array
    {
        $address['id'] = (string) Str::of($address['id'])->afterLast('/')->before('?');

        return $address;
    }
}
