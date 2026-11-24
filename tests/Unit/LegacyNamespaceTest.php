<?php

namespace Thoughtco\Shopify\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Thoughtco\Shopify\Actions\ParseMetafields;
use Thoughtco\Shopify\Events\ProductCreate;
use Thoughtco\Shopify\Jobs\ImportSingleProductJob;
use Thoughtco\Shopify\Tests\TestCase;

class LegacyNamespaceTest extends TestCase
{
    #[Test]
    public function old_class_names_resolve_to_the_new_classes()
    {
        $old = 'StatamicRadPack\\Shopify\\Jobs\\ImportSingleProductJob';

        $this->assertTrue(class_exists($old));
        $this->assertSame(ImportSingleProductJob::class, (new \ReflectionClass($old))->getName());
    }

    #[Test]
    public function old_interfaces_and_enums_resolve()
    {
        $this->assertTrue(interface_exists('StatamicRadPack\\Shopify\\Contracts\\ActivityRepository'));
        $this->assertTrue(enum_exists('StatamicRadPack\\Shopify\\Enums\\WebhookTopic'));
    }

    #[Test]
    public function old_class_names_in_published_config_still_resolve()
    {
        config(['shopify.metafields_parser' => 'StatamicRadPack\\Shopify\\Actions\\ParseMetafields']);

        $this->assertInstanceOf(ParseMetafields::class, app(config('shopify.metafields_parser')));
    }

    #[Test]
    public function payloads_serialized_with_old_class_names_unserialize()
    {
        $new = ProductCreate::class;
        $old = 'StatamicRadPack\\Shopify\\Events\\ProductCreate';

        $payload = str_replace(
            'O:'.strlen($new).':"'.$new.'"',
            'O:'.strlen($old).':"'.$old.'"',
            serialize(new ProductCreate((object) ['id' => 1])),
        );

        $event = unserialize($payload);

        $this->assertInstanceOf(ProductCreate::class, $event);
        $this->assertSame(1, $event->data->id);
    }

    #[Test]
    public function unknown_old_classes_are_not_defined()
    {
        $this->assertFalse(class_exists('StatamicRadPack\\Shopify\\DoesNotExist'));
    }
}
