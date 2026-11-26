<?php

namespace Thoughtco\Shopify\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Parse;
use Thoughtco\Shopify\Support\Money;
use Thoughtco\Shopify\Tests\TestCase;

class MoneyModifierTest extends TestCase
{
    private function parse(string $template, array $data = []): string
    {
        return (string) Parse::template($template, $data, trusted: true);
    }

    #[Test]
    public function formats_a_shopify_money_value_in_its_own_currency()
    {
        $this->assertSame('€12.50', $this->parse('{{ total | shopify_money }}', ['total' => ['amount' => '12.5', 'currencyCode' => 'EUR']]));
        $this->assertSame('£1,234.00', $this->parse('{{ total | shopify_money }}', ['total' => ['amount' => '1234', 'currencyCode' => 'GBP']]));
    }

    #[Test]
    public function formats_an_amount_with_a_currency_param_or_the_stores_currency()
    {
        config()->set('shopify.currency_code', 'GBP');

        $this->assertSame('$9.99', $this->parse('{{ amount | shopify_money("USD") }}', ['amount' => '9.99']));
        $this->assertSame('£9.99', $this->parse('{{ amount | shopify_money }}', ['amount' => '9.99']));
    }

    #[Test]
    public function is_empty_for_anything_but_an_amount()
    {
        $this->assertSame('', $this->parse('{{ total | shopify_money }}', ['total' => ['amount' => null, 'currencyCode' => 'GBP']]));
        $this->assertSame('', $this->parse('{{ amount | shopify_money("GBP") }}', ['amount' => 'free']));
    }

    #[Test]
    public function falls_back_without_a_currency_code()
    {
        config()->set(['shopify.currency_code' => null, 'shopify.currency' => '£']);

        $this->assertSame('£5.00', Money::format(5));
    }
}
