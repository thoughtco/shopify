import { test } from 'node:test';
import assert from 'node:assert/strict';

// a browser-like window for client.js and the error events
const errors = [];
globalThis.window = new EventTarget();
window.shopifyConfig = { url: 'example.myshopify.com', token: 'token', apiVersion: '2026-01' };
window.addEventListener('shopify.cart.error', event => errors.push(event.detail));

// Shopify's client calls fetch: record each request and answer with `respond`
const requests = [];
let respond = () => ({});
globalThis.fetch = async (url, options) => {
    const body = JSON.parse(options.body);
    requests.push(body);

    return new Response(JSON.stringify(respond(body)), { status: 200, headers: { 'Content-Type': 'application/json' } });
};

const cart = await import('../../resources/js/shopify/cart.js');

const fakeCart = { id: 'gid://shopify/Cart/1', discountCodes: [{ code: 'SAVE10', applicable: false }] };

const reset = (response) => {
    requests.length = 0;
    errors.length = 0;
    respond = () => response;
    window.shopifyConfig.country = undefined;
};

test('updateDiscountCodes sends the codes and returns the cart', async () => {
    reset({ data: { cartDiscountCodesUpdate: { cart: fakeCart, userErrors: [] } } });

    const result = await cart.updateDiscountCodes('gid://shopify/Cart/1', ['SAVE10']);

    assert.deepEqual(result, fakeCart);
    assert.match(requests[0].query, /cartDiscountCodesUpdate\(cartId: \$cartId, discountCodes: \$discountCodes\)/);
    assert.match(requests[0].query, /discountCodes \{\s+code\s+applicable/);
    assert.match(requests[0].query, /appliedGiftCards \{/);
    assert.match(requests[0].query, /subtotalAmount \{/);
    assert.deepEqual(requests[0].variables, { cartId: 'gid://shopify/Cart/1', discountCodes: ['SAVE10'] });

    // a code that doesn't apply is returned on the cart, not reported as an error
    assert.equal(errors.length, 0);
});

test('addGiftCardCodes and removeGiftCardCodes use the add and remove mutations', async () => {
    reset({ data: { cartGiftCardCodesAdd: { cart: fakeCart, userErrors: [] }, cartGiftCardCodesRemove: { cart: fakeCart, userErrors: [] } } });

    assert.deepEqual(await cart.addGiftCardCodes('gid://shopify/Cart/1', ['ABCD1234']), fakeCart);
    assert.match(requests[0].query, /cartGiftCardCodesAdd\(cartId: \$cartId, giftCardCodes: \$giftCardCodes\)/);
    assert.deepEqual(requests[0].variables, { cartId: 'gid://shopify/Cart/1', giftCardCodes: ['ABCD1234'] });

    assert.deepEqual(await cart.removeGiftCardCodes('gid://shopify/Cart/1', ['gid://shopify/AppliedGiftCard/1']), fakeCart);
    assert.match(requests[1].query, /cartGiftCardCodesRemove\(cartId: \$cartId, appliedGiftCardIds: \$appliedGiftCardIds\)/);
    assert.deepEqual(requests[1].variables, { cartId: 'gid://shopify/Cart/1', appliedGiftCardIds: ['gid://shopify/AppliedGiftCard/1'] });
});

test('an invalid gift card is reported with the shopify.cart.error event', async () => {
    const userErrors = [{ field: ['giftCardCodes'], message: 'Gift card code is invalid' }];
    reset({ data: { cartGiftCardCodesAdd: { cart: null, userErrors } } });

    assert.equal(await cart.addGiftCardCodes('gid://shopify/Cart/1', ['NOPE']), undefined);
    assert.deepEqual(errors, [{ operation: 'cartGiftCardCodesAdd', errors: userErrors }]);
});

test('discount codes are applied in the market’s context', async () => {
    reset({ data: { cartDiscountCodesUpdate: { cart: fakeCart, userErrors: [] } } });
    window.shopifyConfig.country = 'IE';

    await cart.updateDiscountCodes('gid://shopify/Cart/1', []);

    assert.match(requests[0].query, /@inContext\(country: \$country\)/);
    assert.deepEqual(requests[0].variables, { cartId: 'gid://shopify/Cart/1', discountCodes: [], country: 'IE' });
});
