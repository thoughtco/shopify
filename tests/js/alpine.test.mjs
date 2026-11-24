import { test } from 'node:test';
import assert from 'node:assert/strict';

// a stand-in for Alpine: just enough to register and read the component and stores
const stores = {};
const components = {};
const Alpine = {
    data: (name, callback) => components[name] = callback,
    store: (name, value) => value === undefined ? stores[name] : (stores[name] = value),
};

globalThis.window = { shopifyConfig: {}, addEventListener: () => {} };
globalThis.localStorage = { getItem: () => null, setItem: () => {} };

const { createData, createStore } = await import('../../resources/js/shopify/alpine.js');
createStore(Alpine);
createData(Alpine);

const variant = {
    price: '23.00',
    inventory_policy: 'DENY',
    inventory_quantity: 0,
    market_data: {
        GB: { price: '9.99', inventory_quantity: 0 },
        IE: { price: '12.99', inventory_quantity: 5 },
    },
};

test('shopifyProduct uses the cart store’s market for prices and stock', () => {
    const product = components.shopifyProduct({}, [variant]);

    stores['statamic.shopify.cart'].market = 'IE';
    assert.equal(product.getVariantPrice(variant), '12.99');
    assert.equal(product.outOfStock(variant), false);

    // switching market (shopify.updateMarket updates the store) changes what the product shows
    stores['statamic.shopify.cart'].market = 'GB';
    assert.equal(product.getVariantPrice(variant), '9.99');
    assert.equal(product.outOfStock(variant), true);
});

test('shopifyProduct falls back to the variant without a market', () => {
    const product = components.shopifyProduct({}, [variant]);

    stores['statamic.shopify.cart'].market = undefined;
    assert.equal(product.getVariantPrice(variant), '23.00');
    assert.equal(product.outOfStock(variant), true);
});
