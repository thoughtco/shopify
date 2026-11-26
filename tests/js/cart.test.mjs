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
let buyerResponse = null;
globalThis.fetch = async (url, options) => {
    // the site's buyer identity endpoint (shopify.cart.buyer_identity)
    if (url === '/!/shopify/buyer') {
        return new Response(JSON.stringify(buyerResponse), { status: 200, headers: { 'Content-Type': 'application/json' } });
    }

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
    const withGiftCard = { ...fakeCart, appliedGiftCards: [{ id: 'gid://shopify/AppliedGiftCard/1', lastCharacters: '1234' }] };
    reset({ data: { cartGiftCardCodesAdd: { cart: withGiftCard, userErrors: [] }, cartGiftCardCodesRemove: { cart: fakeCart, userErrors: [] } } });

    assert.deepEqual(await cart.addGiftCardCodes('gid://shopify/Cart/1', ['ABCD1234']), withGiftCard);
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

// buy now: answers cartCreate with `respondToCreate` and the site's buyer endpoint with `buyer`
const buyNowSetup = ({ buyer = null, respondToCreate } = {}) => {
    reset();
    respond = respondToCreate;
    buyerResponse = buyer;
    window.shopifyConfig.buyerIdentityUrl = buyer ? '/!/shopify/buyer' : undefined;

    const redirects = [];
    window.location = { assign: url => redirects.push(url) };

    return redirects;
};

const checkoutCart = { id: 'gid://shopify/Cart/2', checkoutUrl: 'https://example.myshopify.com/cart/c/2' };
const lines = [{ merchandiseId: 'gid://shopify/ProductVariant/1', quantity: 1 }];

test('buyNow creates a cart for just these lines and goes to its checkout', async () => {
    const redirects = buyNowSetup({ respondToCreate: () => ({ data: { cartCreate: { cart: checkoutCart, userErrors: [] } } }) });
    window.shopifyConfig.country = 'IE';

    const result = await cart.buyNow(lines, { discountCodes: ['SAVE10'], attributes: [{ key: 'gift', value: 'yes' }] });

    assert.deepEqual(result, checkoutCart);
    assert.equal(requests.length, 1);
    assert.match(requests[0].query, /cartCreate\(input: \$cartInput\)/);
    assert.deepEqual(requests[0].variables.cartInput, {
        discountCodes: ['SAVE10'],
        attributes: [{ key: 'gift', value: 'yes' }],
        lines,
        buyerIdentity: { countryCode: 'IE' },
    });
    assert.deepEqual(redirects, ['https://example.myshopify.com/cart/c/2']);
});

test('buyNow adds the logged in customer’s buyer identity and delivery address', async () => {
    const deliveryAddress = { firstName: 'Ada', address1: '1 Main St', city: 'Belfast', countryCode: 'GB', zip: 'BT1 1AA' };
    buyNowSetup({
        buyer: { email: 'ada@example.com', phone: '+447700900000', deliveryAddress },
        respondToCreate: () => ({ data: { cartCreate: { cart: checkoutCart, userErrors: [] } } }),
    });

    await cart.buyNow(lines);

    assert.deepEqual(requests[0].variables.cartInput.buyerIdentity, { email: 'ada@example.com', phone: '+447700900000' });
    assert.deepEqual(requests[0].variables.cartInput.delivery, { addresses: [{ address: { deliveryAddress }, selected: true }] });
});

test('buyNow retries without the buyer identity when Shopify rejects it', async () => {
    const redirects = buyNowSetup({
        buyer: { email: 'not-an-email' },
        respondToCreate: (body) => body.variables.cartInput.buyerIdentity
            ? { data: { cartCreate: { cart: null, userErrors: [{ field: ['input', 'buyerIdentity', 'email'], message: 'Email is invalid' }] } } }
            : { data: { cartCreate: { cart: checkoutCart, userErrors: [] } } },
    });

    assert.deepEqual(await cart.buyNow(lines), checkoutCart);
    assert.equal(requests.length, 2);
    assert.equal(requests[1].variables.cartInput.buyerIdentity, undefined);
    assert.deepEqual(redirects, [checkoutCart.checkoutUrl]);

    // the rejected identity isn't reported, as the purchase carries on
    assert.equal(errors.length, 0);
});

test('buyNow reports a failure and stays on the page', async () => {
    const userErrors = [{ field: ['input', 'lines'], message: 'The merchandise is out of stock' }];
    const redirects = buyNowSetup({ respondToCreate: () => ({ data: { cartCreate: { cart: null, userErrors } } }) });

    assert.equal(await cart.buyNow(lines), undefined);
    assert.deepEqual(errors, [{ operation: 'cartCreate', errors: userErrors }]);
    assert.deepEqual(redirects, []);
});

test('buyNow can return the cart without going to checkout', async () => {
    const redirects = buyNowSetup({ respondToCreate: () => ({ data: { cartCreate: { cart: checkoutCart, userErrors: [] } } }) });

    assert.deepEqual(await cart.buyNow(lines, { redirect: false }), checkoutCart);
    assert.equal(requests[0].variables.cartInput.redirect, undefined);
    assert.deepEqual(redirects, []);
});
