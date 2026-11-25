import { test } from 'node:test';
import assert from 'node:assert/strict';

// a stand-in for Alpine: just enough to register and read the cart store
const stores = {};
const Alpine = {
    data: () => {},
    store: (name, value) => value === undefined ? stores[name] : (stores[name] = value),
};

const errors = [];
globalThis.window = new EventTarget();
window.shopifyConfig = { url: 'example.myshopify.com', token: 'token', currency: '£' };
window.addEventListener('shopify.cart.error', event => errors.push(event.detail));
globalThis.localStorage = { getItem: () => null, setItem: () => {} };

// Shopify's client calls fetch: record each request and answer with `respond`
const requests = [];
let respond = () => ({});
globalThis.fetch = async (url, options) => {
    const body = JSON.parse(options.body);
    requests.push(body);

    return new Response(JSON.stringify(respond(body)), { status: 200, headers: { 'Content-Type': 'application/json' } });
};

const { createStore } = await import('../../resources/js/shopify/alpine.js');
createStore(Alpine);
const store = stores['statamic.shopify.cart'];

const money = (amount) => ({ amount, currencyCode: 'GBP' });

const cartResponse = ({ discountCodes = [], appliedGiftCards = [], lineDiscount = null } = {}) => ({
    id: 'gid://shopify/Cart/1',
    checkoutUrl: 'https://example.myshopify.com/cart/c/1',
    note: '',
    cost: { subtotalAmount: money('20.0'), totalAmount: money('18.0') },
    lines: {
        edges: [{
            node: {
                id: 'gid://shopify/CartLine/1',
                attributes: [],
                discountAllocations: lineDiscount ? [{ discountedAmount: money(lineDiscount) }] : [],
                cost: { amountPerQuantity: money('10.0') },
                merchandise: { id: 'gid://shopify/ProductVariant/1', title: 'Default', product: { title: 'Shirt' } },
                quantity: 2,
            },
        }],
    },
    discountCodes,
    appliedGiftCards,
});

const reset = (name, cart) => {
    requests.length = 0;
    errors.length = 0;
    respond = () => ({ data: { [name]: { cart, userErrors: [] } } });
};

test('updateFromResponse maps discounts, codes, gift cards and the subtotal', () => {
    store.updateFromResponse(cartResponse({
        discountCodes: [{ code: 'SAVE10', applicable: true }],
        appliedGiftCards: [{ id: 'gid://shopify/AppliedGiftCard/1', lastCharacters: '1234', amountUsed: money('5.0'), balance: money('15.5') }],
        lineDiscount: '2.0',
    }));

    assert.equal(store.itemsSubtotal, '£20.00');
    assert.equal(store.itemsSubtotalAmount, 20);
    assert.equal(store.subtotal, '£18.00');
    assert.equal(store.discount, '£2.00');
    assert.equal(store.discountAmount, 2);
    assert.equal(store.lineItems[0].discount, '£2.00');
    assert.deepEqual(store.discountCodes, [{ code: 'SAVE10', applicable: true }]);
    assert.deepEqual(store.giftCards, [{
        id: 'gid://shopify/AppliedGiftCard/1',
        lastCharacters: '1234',
        amountUsed: '£5.00',
        amountUsedAmount: 5,
        balance: '£15.50',
        balanceAmount: 15.5,
        currencyCode: 'GBP',
    }]);
});

test('a cart without discounts has no discount', () => {
    store.updateFromResponse(cartResponse());

    assert.equal(store.discount, null);
    assert.equal(store.discountAmount, 0);
    assert.equal(store.lineItems[0].discount, null);
    assert.deepEqual(store.discountCodes, []);
    assert.deepEqual(store.giftCards, []);
});

test('applyDiscountCode keeps the cart’s other codes and shows a code that doesn’t apply', async () => {
    store.updateFromResponse(cartResponse({ discountCodes: [{ code: 'WELCOME', applicable: true }] }));
    reset('cartDiscountCodesUpdate', cartResponse({ discountCodes: [{ code: 'WELCOME', applicable: true }, { code: 'NOTHING', applicable: false }] }));

    const result = await store.applyDiscountCode(' NOTHING ');

    assert.ok(result);
    assert.deepEqual(requests[0].variables.discountCodes, ['WELCOME', 'NOTHING']);
    assert.deepEqual(store.discountCodes[1], { code: 'NOTHING', applicable: false });
    assert.equal(errors.length, 0);
});

test('applyDiscountCode ignores an empty code and doesn’t add a code twice', async () => {
    store.updateFromResponse(cartResponse({ discountCodes: [{ code: 'WELCOME', applicable: true }] }));
    reset('cartDiscountCodesUpdate', cartResponse({ discountCodes: [{ code: 'WELCOME', applicable: true }] }));

    assert.equal(await store.applyDiscountCode('  '), undefined);
    assert.equal(requests.length, 0);

    await store.applyDiscountCode('welcome');
    assert.deepEqual(requests[0].variables.discountCodes, ['welcome']);
});

test('removeDiscountCode sends the remaining codes', async () => {
    store.updateFromResponse(cartResponse({ discountCodes: [{ code: 'WELCOME', applicable: true }, { code: 'SAVE10', applicable: true }] }));
    reset('cartDiscountCodesUpdate', cartResponse({ discountCodes: [{ code: 'SAVE10', applicable: true }] }));

    await store.removeDiscountCode('welcome');

    assert.deepEqual(requests[0].variables.discountCodes, ['SAVE10']);
    assert.deepEqual(store.discountCodes, [{ code: 'SAVE10', applicable: true }]);
});

test('applyGiftCardCode and removeGiftCard update the gift cards', async () => {
    const giftCard = { id: 'gid://shopify/AppliedGiftCard/1', lastCharacters: '1234', amountUsed: money('5.0'), balance: money('0.0') };

    store.updateFromResponse(cartResponse());
    reset('cartGiftCardCodesAdd', cartResponse({ appliedGiftCards: [giftCard] }));
    await store.applyGiftCardCode('GIFT 1234 ');
    assert.deepEqual(requests[0].variables.giftCardCodes, ['GIFT 1234']);
    assert.equal(store.giftCards[0].lastCharacters, '1234');

    reset('cartGiftCardCodesRemove', cartResponse());
    await store.removeGiftCard(giftCard.id);
    assert.deepEqual(requests[0].variables.appliedGiftCardIds, [giftCard.id]);
    assert.deepEqual(store.giftCards, []);
});

test('a rejected gift card leaves the cart as it was and sends shopify.cart.error', async () => {
    const giftCard = { id: 'gid://shopify/AppliedGiftCard/1', lastCharacters: '1234', amountUsed: money('5.0'), balance: money('0.0') };
    store.updateFromResponse(cartResponse({ appliedGiftCards: [giftCard] }));

    requests.length = 0;
    errors.length = 0;
    respond = () => ({ data: { cartGiftCardCodesAdd: { cart: null, userErrors: [{ field: ['giftCardCodes'], message: 'Gift card code is invalid' }] } } });

    assert.equal(await store.applyGiftCardCode('NOPE'), undefined);
    assert.equal(errors[0].operation, 'cartGiftCardCodesAdd');
    assert.equal(store.giftCards.length, 1);
});

test('applying a code without a cart creates one first', async () => {
    store.cartId = null;
    store.discountCodes = [];
    requests.length = 0;
    respond = (body) => body.query.includes('cartCreate')
        ? { data: { cartCreate: { cart: cartResponse(), userErrors: [] } } }
        : { data: { cartDiscountCodesUpdate: { cart: cartResponse({ discountCodes: [{ code: 'SAVE10', applicable: true }] }), userErrors: [] } } };

    await store.applyDiscountCode('SAVE10');

    assert.match(requests[0].query, /cartCreate/);
    assert.deepEqual(requests[1].variables, { cartId: 'gid://shopify/Cart/1', discountCodes: ['SAVE10'] });
});
