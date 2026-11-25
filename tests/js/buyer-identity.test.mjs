import { beforeEach, test } from 'node:test';
import assert from 'node:assert/strict';

const stores = {};
const Alpine = {
    data: () => {},
    store: (name, value) => value === undefined ? stores[name] : (stores[name] = value),
};

const storage = {};
globalThis.window = { shopifyConfig: {}, addEventListener: () => {} };
globalThis.localStorage = {
    getItem: (key) => storage[key] ?? null,
    setItem: (key, value) => storage[key] = value,
};

// Storefront API requests and buyer endpoint fetches, instead of the network
let requests = [];
let fetches = [];
let buyerResponse;

const { default: client } = await import('../../resources/js/shopify/client.js');
client.request = async (query, { variables }) => {
    requests.push({ query, variables });

    const name = query.match(/(?:mutation|query) (\w+)/)[1];

    return { data: { [name]: { cart: cart(variables.cartId), userErrors: [] } } };
};

globalThis.fetch = async (url, options) => {
    fetches.push({ url, options });

    return buyerResponse;
};

const cart = (id) => ({
    id,
    checkoutUrl: 'https://example.myshopify.com/cart/c/1',
    cost: { totalAmount: { amount: '10.00', currencyCode: 'GBP' } },
    lines: { edges: [] },
    note: '',
});

const json = (body) => ({ ok: true, status: 200, json: async () => body });
const noContent = () => ({ ok: true, status: 204, json: async () => { throw new Error('no body'); } });

const deliveryAddress = { firstName: 'Sam', address1: '1 High St', city: 'London', countryCode: 'GB', zip: 'N1 1AA' };
const buyer = { email: 'sam@example.com', phone: '+447700900000' };
const identity = { ...buyer, deliveryAddress };

const { getBuyerIdentity, setDeliveryAddress, updateBuyerIdentity } = await import('../../resources/js/shopify/cart.js');
const { createStore } = await import('../../resources/js/shopify/alpine.js');
createStore(Alpine);

const store = () => stores['statamic.shopify.cart'];
const settle = () => new Promise(resolve => setTimeout(resolve, 0));

beforeEach(() => {
    requests = [];
    fetches = [];
    buyerResponse = json(identity);
    window.shopifyConfig = { buyerIdentityUrl: '/!/shopify/buyer' };
    Object.keys(storage).forEach(key => delete storage[key]);
    store().cartId = null;
    store().buyerIdentityCartId = null;
});

test('updateBuyerIdentity sends the buyer identity', async () => {
    const result = await updateBuyerIdentity('gid://shopify/Cart/1', buyer);

    assert.equal(result.id, 'gid://shopify/Cart/1');
    assert.match(requests[0].query, /cartBuyerIdentityUpdate\(cartId: \$cartId, buyerIdentity: \$buyerIdentity\)/);
    assert.deepEqual(requests[0].variables, { cartId: 'gid://shopify/Cart/1', buyerIdentity: buyer });
});

test('setDeliveryAddress replaces the cart’s delivery addresses with a selected one', async () => {
    const result = await setDeliveryAddress('gid://shopify/Cart/1', deliveryAddress);

    assert.equal(result.id, 'gid://shopify/Cart/1');
    assert.match(requests[0].query, /cartDeliveryAddressesReplace\(cartId: \$cartId, addresses: \$addresses\)/);
    assert.deepEqual(requests[0].variables, { cartId: 'gid://shopify/Cart/1', addresses: [{ address: { deliveryAddress }, selected: true }] });
});

test('updateBuyerIdentity keeps the cart in the market country', async () => {
    window.shopifyConfig.country = 'IE';

    await updateBuyerIdentity('gid://shopify/Cart/1', { ...buyer, countryCode: 'GB' });

    assert.equal(requests[0].variables.buyerIdentity.countryCode, 'IE');
    assert.equal(requests[0].variables.buyerIdentity.email, 'sam@example.com');
    assert.equal(requests[0].variables.country, 'IE');
});

test('getBuyerIdentity is off without shopifyConfig.buyerIdentityUrl', async () => {
    window.shopifyConfig = {};

    assert.equal(await getBuyerIdentity(), null);
    assert.equal(fetches.length, 0);
});

test('getBuyerIdentity fetches the endpoint with the store', async () => {
    window.shopifyConfig.store = 'uk';

    assert.deepEqual(await getBuyerIdentity(), identity);
    assert.equal(fetches[0].url, '/!/shopify/buyer?store=uk');
    assert.equal(fetches[0].options.credentials, 'same-origin');
});

test('getBuyerIdentity is null for guests and failures', async () => {
    buyerResponse = noContent();
    assert.equal(await getBuyerIdentity(), null);

    buyerResponse = { ok: false, status: 500, json: async () => ({}) };
    assert.equal(await getBuyerIdentity(), null);

    globalThis.fetch = async () => { throw new Error('offline'); };
    assert.equal(await getBuyerIdentity(), null);

    globalThis.fetch = async (url, options) => {
        fetches.push({ url, options });

        return buyerResponse;
    };
});

test('the cart store applies the buyer identity once per cart', async () => {
    store().updateFromResponse(cart('gid://shopify/Cart/1'));
    await settle();

    assert.equal(fetches.length, 1);
    assert.equal(requests.length, 2);
    assert.deepEqual(requests[0].variables.buyerIdentity, buyer);
    assert.deepEqual(requests[1].variables.addresses, [{ address: { deliveryAddress }, selected: true }]);
    assert.equal(storage['statamic.shopify.cart.id:buyer'], 'gid://shopify/Cart/1');

    // later responses for the same cart, and later page loads, don't apply it again
    store().updateFromResponse(cart('gid://shopify/Cart/1'));
    store().buyerIdentityCartId = null;
    store().updateFromResponse(cart('gid://shopify/Cart/1'));
    await settle();

    assert.equal(fetches.length, 1);
    assert.equal(requests.length, 2);

    // a new cart gets it
    store().updateFromResponse(cart('gid://shopify/Cart/2'));
    await settle();

    assert.equal(requests.length, 4);
    assert.equal(requests[2].variables.cartId, 'gid://shopify/Cart/2');
});

test('a rejected delivery address keeps the buyer identity and isn’t retried', async () => {
    const request = client.request;
    client.request = async (query, options) => query.includes('cartDeliveryAddressesReplace')
        ? (requests.push({ query, variables: options.variables }), { data: { cartDeliveryAddressesReplace: { cart: null, userErrors: [{ field: ['addresses'], message: 'Invalid address' }] } } })
        : request(query, options);

    store().updateFromResponse(cart('gid://shopify/Cart/1'));
    await settle();

    client.request = request;

    assert.equal(requests.length, 2);
    assert.equal(store().cartId, 'gid://shopify/Cart/1');
    assert.equal(storage['statamic.shopify.cart.id:buyer'], 'gid://shopify/Cart/1');
});

test('a rejected email still sets the delivery address', async () => {
    const request = client.request;
    client.request = async (query, options) => query.includes('cartBuyerIdentityUpdate')
        ? (requests.push({ query, variables: options.variables }), { data: { cartBuyerIdentityUpdate: { cart: null, userErrors: [{ field: ['buyerIdentity', 'email'], message: 'Email is invalid' }] } } })
        : request(query, options);

    store().updateFromResponse(cart('gid://shopify/Cart/1'));
    await settle();

    client.request = request;

    assert.equal(requests.length, 2);
    assert.match(requests[1].query, /cartDeliveryAddressesReplace/);
    assert.equal(storage['statamic.shopify.cart.id:buyer'], 'gid://shopify/Cart/1');
});

test('the cart store leaves guests’ carts alone, and checks again on the next page load', async () => {
    buyerResponse = noContent();

    store().updateFromResponse(cart('gid://shopify/Cart/1'));
    await settle();

    assert.equal(fetches.length, 1);
    assert.equal(requests.length, 0);
    assert.equal(storage['statamic.shopify.cart.id:buyer'], undefined);

    // the same page doesn't ask again
    store().updateFromResponse(cart('gid://shopify/Cart/1'));
    await settle();
    assert.equal(fetches.length, 1);
});

test('the cart store does nothing when buyer identity is off', async () => {
    window.shopifyConfig = {};

    store().updateFromResponse(cart('gid://shopify/Cart/1'));
    await settle();

    assert.equal(fetches.length, 0);
    assert.equal(requests.length, 0);
});
