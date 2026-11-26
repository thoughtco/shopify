<?php

namespace Thoughtco\Shopify\Support;

use NumberFormatter;
use Statamic\Facades\Site;

/**
 * Currency settings and price formatting, shared by the tags and the
 * window.shopifyConfig values the bundled cart JS formats prices with.
 */
class Money
{
    /**
     * The currency symbol and ISO code for a store or market handle (or the default store).
     *
     * @return array{symbol: string, code: ?string}
     */
    public static function currency(?string $store = null): array
    {
        $config = match (true) {
            $store && StoreConfig::isMarketsMode() => StoreConfig::getMarket($store) ?? [],
            $store && StoreConfig::isMultiStore() => StoreConfig::findByHandle($store) ?? [],
            default => [],
        };

        return [
            'symbol' => $config['currency'] ?? config('shopify.currency', '$'),
            'code' => $config['currency_code'] ?? config('shopify.currency_code'),
        ];
    }

    public static function usesIntl(): bool
    {
        return config('shopify.money_format', 'symbol') === 'intl';
    }

    /**
     * The current site's locale as a BCP 47 tag (en_GB => en-GB), as used by Intl.NumberFormat.
     */
    public static function locale(): string
    {
        return str_replace('_', '-', Site::current()->locale());
    }

    /**
     * An amount in a given currency (an order's total, say), formatted for the current
     * site's locale with PHP's intl extension. Without intl, or a currency code, it falls
     * back to the configured symbol when the code is the store's own, else "12.50 EUR".
     */
    public static function format($amount, ?string $currencyCode = null): string
    {
        if (! is_numeric($amount)) {
            return '';
        }

        $currencyCode = $currencyCode ? strtoupper($currencyCode) : config('shopify.currency_code');

        if ($currencyCode && class_exists(NumberFormatter::class)) {
            $formatted = (new NumberFormatter(Site::current()->locale(), NumberFormatter::CURRENCY))->formatCurrency((float) $amount, $currencyCode);

            if ($formatted !== false) {
                return $formatted;
            }
        }

        $number = number_format((float) $amount, 2);

        if (! $currencyCode || $currencyCode === config('shopify.currency_code')) {
            return config('shopify.currency', '$').$number;
        }

        return "{$number} {$currencyCode}";
    }

    /**
     * Split a price into the currency and amount parts used by the price messages
     * (`:currency:price`). With the 'intl' format the whole formatted price is in
     * `price` and `currency` is empty; otherwise this is the symbol and the raw price.
     *
     * @return array{currency: string, price: mixed}
     */
    public static function parts($price, ?string $store = null): array
    {
        $currency = static::currency($store);

        if (static::usesIntl() && $currency['code'] && is_numeric($price) && class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter(Site::current()->locale(), NumberFormatter::CURRENCY);
            $formatted = $formatter->formatCurrency((float) $price, $currency['code']);

            if ($formatted !== false) {
                return ['currency' => '', 'price' => $formatted];
            }
        }

        return ['currency' => $currency['symbol'], 'price' => $price];
    }
}
