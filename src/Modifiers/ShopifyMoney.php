<?php

namespace Thoughtco\Shopify\Modifiers;

use Statamic\Modifiers\Modifier;
use Thoughtco\Shopify\Support\Money;

/**
 * Formats an amount in its own currency, e.g. an order's total:
 * {{ totalPriceSet:presentmentMoney | shopify_money }} or {{ amount | shopify_money('EUR') }}.
 */
class ShopifyMoney extends Modifier
{
    protected static $handle = 'shopify_money';

    public function index($value, $params)
    {
        // a Shopify MoneyV2: { amount, currencyCode }
        if (is_array($value) || $value instanceof \ArrayAccess) {
            return Money::format($value['amount'] ?? null, $value['currencyCode'] ?? ($params[0] ?? null));
        }

        return Money::format($value, $params[0] ?? null);
    }
}
