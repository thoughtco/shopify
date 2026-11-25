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

const sellingPlans = {
    requires_selling_plan: false,
    groups: [{
        name: 'Subscribe & save',
        selling_plans: [
            { id: 'gid://shopify/SellingPlan/1', name: 'Monthly', pricing_policies: [{ adjustment_type: 'PERCENTAGE', adjustment_value: 10 }] },
            { id: 'gid://shopify/SellingPlan/2', name: 'Weekly', pricing_policies: [{ adjustment_type: 'FIXED_AMOUNT', adjustment_value: 5 }] },
        ],
    }],
};

const planVariants = [
    { slug: '1', price: '20.00', selling_plan_ids: ['gid://shopify/SellingPlan/1', 'gid://shopify/SellingPlan/2'] },
    { slug: '2', price: '30.00', selling_plan_ids: ['gid://shopify/SellingPlan/2'] },
];

const makeProduct = (plans, variants = planVariants) => {
    const product = components.shopifyProduct({}, variants, plans);
    product.$el = { querySelector: () => ({ value: '' }) };
    product.init();

    return product;
};

test('shopifyProduct defaults to a one-off purchase and can switch to a subscription', () => {
    stores['statamic.shopify.cart'].market = undefined;
    const product = makeProduct(sellingPlans);

    assert.equal(product.purchaseType, 'one_time');
    assert.equal(product.currentSellingPlan(), null);
    assert.equal(product.hasSellingPlans(), true);

    product.setPurchaseType('subscription');
    assert.equal(product.currentSellingPlan().name, 'Monthly');
    assert.equal(product.getSellingPlanPrice(), 18);

    product.selectSellingPlan('gid://shopify/SellingPlan/2');
    assert.equal(product.getSellingPlanPrice(), 15);

    product.setPurchaseType('one_time');
    assert.equal(product.currentSellingPlan(), null);
});

test('shopifyProduct only uses plans the selected variant has', () => {
    stores['statamic.shopify.cart'].market = undefined;
    const product = makeProduct(sellingPlans);

    product.selectSellingPlan('gid://shopify/SellingPlan/1');
    product.selectedVariant = planVariants[1];

    assert.deepEqual(product.availableSellingPlans().map(plan => plan.name), ['Weekly']);
    assert.equal(product.currentSellingPlan().name, 'Weekly');
});

test('shopifyProduct enforces a subscription when the product requires a selling plan', () => {
    stores['statamic.shopify.cart'].market = undefined;
    const product = makeProduct({ ...sellingPlans, requires_selling_plan: true });

    assert.equal(product.purchaseType, 'subscription');
    assert.equal(product.sellingPlanId, 'gid://shopify/SellingPlan/1');

    product.setPurchaseType('one_time');
    assert.equal(product.purchaseType, 'subscription');
    assert.equal(product.sellingPlanSatisfied(), true);

    // a variant without plans can't be bought
    product.selectedVariant = { slug: '3', price: '5.00', selling_plan_ids: [] };
    assert.equal(product.sellingPlanSatisfied(), false);
});

test('shopifyProduct without selling plans is unchanged', () => {
    const product = makeProduct(undefined, [variant]);

    assert.equal(product.purchaseType, 'one_time');
    assert.equal(product.hasSellingPlans(), false);
    assert.equal(product.sellingPlanSatisfied(), true);
    assert.equal(product.getSellingPlanPrice(), null);
});

test('shopifyProduct only applies percentage discounts to a market price', () => {
    stores['statamic.shopify.cart'].market = 'IE';
    const product = makeProduct(sellingPlans, [{ ...variant, selling_plan_ids: undefined }]);

    product.setPurchaseType('subscription');
    assert.equal(product.getSellingPlanPrice(), 11.69);

    product.selectSellingPlan('gid://shopify/SellingPlan/2');
    assert.equal(product.getSellingPlanPrice(), null);
    stores['statamic.shopify.cart'].market = undefined;
});

test('shopifyProduct uses the imported market price on a plan for the active market', () => {
    const marketVariant = {
        ...variant,
        selling_plan_ids: undefined,
        market_data: {
            GB: { price: '9.99', inventory_quantity: 0 },
            IE: { price: '12.99', inventory_quantity: 5, selling_plans: {
                'gid://shopify/SellingPlan/1': { price: '11.50', compare_at_price: '12.99', per_delivery_price: '11.50' },
                'gid://shopify/SellingPlan/2': { price: '8.00' },
            } },
        },
    };

    stores['statamic.shopify.cart'].market = 'IE';
    const product = makeProduct(sellingPlans, [marketVariant]);
    product.setPurchaseType('subscription');

    // the stored price, not 10% off 12.99
    assert.equal(product.getSellingPlanPrice(), 11.5);

    // a fixed amount plan has a price too, now it's stored
    product.selectSellingPlan('gid://shopify/SellingPlan/2');
    assert.equal(product.getSellingPlanPrice(), 8);

    // another market without stored prices falls back: percentage maths, nothing for fixed amounts
    stores['statamic.shopify.cart'].market = 'GB';
    assert.equal(product.getSellingPlanPrice(), null);
    product.selectSellingPlan('gid://shopify/SellingPlan/1');
    assert.equal(product.getSellingPlanPrice(), 8.99);

    stores['statamic.shopify.cart'].market = undefined;
});

test('handleSubmit sends the selling plan and the cart maps the allocation', async () => {
    stores['statamic.shopify.cart'].market = undefined;
    window.shopifyConfig = { url: 'test.myshopify.com', token: 'token', apiVersion: '2026-01' };

    const cart = (lines = []) => ({
        id: 'gid://shopify/Cart/1',
        checkoutUrl: 'https://checkout',
        note: '',
        buyerIdentity: { countryCode: 'GB' },
        cost: { totalAmount: { amount: '18.00', currencyCode: 'GBP' } },
        lines: { edges: lines },
    });

    const requests = [];
    globalThis.fetch = async (url, options) => {
        const body = JSON.parse(options.body);
        requests.push(body);

        const data = body.query.includes('cartLinesAdd')
            ? { cartLinesAdd: { userErrors: [], cart: cart([{ node: {
                id: 'line', quantity: 1, attributes: [],
                cost: { amountPerQuantity: { amount: '18.00', currencyCode: 'GBP' } },
                merchandise: { id: 'gid://shopify/ProductVariant/1', title: 'Default', product: { title: 'Coffee' } },
                sellingPlanAllocation: { sellingPlan: { id: 'gid://shopify/SellingPlan/1', name: 'Monthly' }, priceAdjustments: [] },
            } }]) } }
            : { cartCreate: { userErrors: [], cart: cart() } };

        return new Response(JSON.stringify({ data }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    };

    const product = components.shopifyProduct({}, planVariants, sellingPlans);
    product.$el = { querySelector: () => ({ value: '1' }) };
    product.$refs = { qty: { value: '1' } };
    product.init();
    product.setPurchaseType('subscription');

    await product.handleSubmit();

    const add = requests.find(request => request.query.includes('cartLinesAdd'));
    assert.match(add.query, /sellingPlanAllocation/);
    assert.equal(add.variables.lines[0].sellingPlanId, 'gid://shopify/SellingPlan/1');
    assert.deepEqual(stores['statamic.shopify.cart'].lineItems[0].sellingPlan, { id: 'gid://shopify/SellingPlan/1', name: 'Monthly' });

    // one-off purchases don't send a plan
    product.setPurchaseType('one_time');
    await product.handleSubmit();
    const oneOff = requests.filter(request => request.query.includes('cartLinesAdd')).pop();
    assert.equal(oneOff.variables.lines[0].sellingPlanId, undefined);
});
