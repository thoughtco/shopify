<?php

namespace Thoughtco\Shopify\Support;

/**
 * Lets code written against the old StatamicRadPack\Shopify namespace keep working
 * (published config, hooks, custom subclasses, jobs queued before upgrading).
 *
 * @deprecated Will be removed in the next major version.
 */
class LegacyNamespace
{
    public const OLD = 'StatamicRadPack\\Shopify\\';

    public const NEW = 'Thoughtco\\Shopify\\';

    private static bool $registered = false;

    public static function register(): void
    {
        if (static::$registered) {
            return;
        }

        spl_autoload_register(static::load(...));

        static::$registered = true;
    }

    public static function load(string $class): void
    {
        if (! str_starts_with($class, static::OLD)) {
            return;
        }

        $new = static::NEW.substr($class, strlen(static::OLD));

        if (! class_exists($new) && ! interface_exists($new) && ! trait_exists($new) && ! enum_exists($new)) {
            return;
        }

        class_alias($new, $class);

        @trigger_error("{$class} is deprecated, use {$new} instead.", E_USER_DEPRECATED);
    }
}
