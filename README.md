<!-- statamic:hide -->
<div align="center">
    <a href="#">
        <h1>Statamic Shopify</h1>
    </a>

<p>

[![Latest Stable Version](https://poser.pugx.org/thoughtco/shopify/v)](//packagist.org/packages/thoughtco/shopify)
[![Total Downloads](https://poser.pugx.org/thoughtco/shopify/downloads)](//packagist.org/packages/thoughtco/shopify)
[![License](https://poser.pugx.org/thoughtco/shopify/license)](//packagist.org/packages/thoughtco/shopify)

</p>
</div>

A Statamic addon that integrates Shopify with Statamic CMS. Use Shopify for world-class ecommerce while building your storefront with Antlers.

[Live Demo](https://statamic-shopify-demostore.vercel.app) —
[Documentation](https://docs.tc/shopify) —
[Issues](https://github.com/thoughtco/shopify/issues) —
[Discussions](https://github.com/thoughtco/shopify/discussions)
<!-- /statamic:hide -->

## Requirements

- PHP 8.3 or 8.4
- Laravel 12
- Statamic 6

## Features

- Import products, variants, images, and collections from Shopify into Statamic
- Real-time sync via Shopify webhooks (products, orders, collections, customers)
- Antlers tags for frontend product display, cart, and customer account pages
- Control Panel UI for triggering imports and managing variants
- User sync between Statamic and Shopify
- Metafields support with a swappable parser
- Multisite / localisation support
- Multi-store support (unified, localized, and markets modes)
- Works with flat-file or database-backed Statamic

## Installation

Install via Composer:

```bash
composer require thoughtco/shopify
```

Publish the config file:

```bash
php artisan vendor:publish --tag=shopify-config
```

Publish the frontend JS assets (optional, for Storefront API cart integration):

```bash
php artisan vendor:publish --tag=shopify-scripts
```

## Documentation

Full documentation on configuration, webhooks, Antlers tags, multi-store setup, and more is available at [docs.tc/shopify](https://docs.tc/shopify). The source lives in [`docs/`](docs).

## Issues & Feedback

Please open an issue on [GitHub](https://github.com/thoughtco/shopify/issues) if you encounter a problem.

To propose a new feature, start a [discussion](https://github.com/thoughtco/shopify/discussions).
