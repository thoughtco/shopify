<?php

namespace Thoughtco\Shopify\Http\Controllers\Actions;

use Illuminate\Support\Carbon;
use Statamic\Support\Arr;
use Thoughtco\Shopify\Http\Requests\UpdateMarketingConsentRequest;

class MarketingConsentController extends BaseActionController
{
    /**
     * Subscribe the logged in user's Shopify customer to, or unsubscribe them from,
     * email and SMS marketing. SMS needs a phone number on the customer.
     */
    public function update(UpdateMarketingConsentRequest $request)
    {
        if (! $customerId = $this->customerId($request)) {
            return $this->withErrors($request, __('No Shopify customer is associated with the current user'));
        }

        $channels = Arr::only($request->validated(), ['email', 'sms']);

        if (! $channels) {
            return $this->withErrors($request, __('Choose which marketing to change'));
        }

        $consent = [];

        try {
            foreach ($channels as $channel => $subscribed) {
                [$operation, $field, $input] = $channel === 'email'
                    ? ['customerEmailMarketingConsentUpdate', 'emailMarketingConsent', 'CustomerEmailMarketingConsentUpdateInput']
                    : ['customerSmsMarketingConsentUpdate', 'smsMarketingConsent', 'CustomerSmsMarketingConsentUpdateInput'];

                $customer = $this->mutate($request, $operation, <<<QUERY
                    mutation {$operation}(\$input: {$input}!) {
                      {$operation}(input: \$input) {
                        customer {
                          {$field} {
                            marketingState
                          }
                        }
                        userErrors {
                          field
                          message
                        }
                      }
                    }
                    QUERY, [
                    'input' => [
                        'customerId' => 'gid://shopify/Customer/'.$customerId,
                        $field => [
                            'marketingState' => $subscribed ? 'SUBSCRIBED' : 'UNSUBSCRIBED',
                            'marketingOptInLevel' => 'SINGLE_OPT_IN',
                            'consentUpdatedAt' => Carbon::now()->toIso8601String(),
                        ],
                    ],
                ], 'customer');

                $consent["{$channel}_marketing_state"] = Arr::get($customer, "{$field}.marketingState");
            }

            return $this->withSuccess($request, array_merge(['message' => __('Marketing preferences updated')], $consent));
        } catch (\Exception $error) {
            return $this->withErrors($request, $error->getMessage());
        }
    }
}
