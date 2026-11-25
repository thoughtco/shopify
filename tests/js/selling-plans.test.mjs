import assert from 'node:assert/strict';
import { test } from 'node:test';
import { allSellingPlans, initialPricingPolicy, marketSellingPlanPrice, sellingPlanLabel, sellingPlanPrice, sellingPlansForVariant } from '../../resources/js/shopify/selling-plans.js';

const plan = (id, type, value, afterCycle = null) => ({
    id: `gid://shopify/SellingPlan/${id}`,
    name: `Plan ${id}`,
    pricing_policies: [{ adjustment_type: type, adjustment_value: value, after_cycle: afterCycle }],
});

const groups = [
    { name: 'Subscribe & save', app_id: 'app', selling_plans: [plan(1, 'PERCENTAGE', 10), plan(2, 'FIXED_AMOUNT', 5)] },
    { name: 'Prepaid', selling_plans: [plan(3, 'PRICE', 15)] },
];

test('plans are flattened with their group name', () => {
    const plans = allSellingPlans(groups);

    assert.equal(plans.length, 3);
    assert.equal(plans[0].group_name, 'Subscribe & save');
    assert.equal(plans[2].app_id, null);
    assert.deepEqual(allSellingPlans(null), []);
});

test('a variant only gets its own plans, or all of them without selling_plan_ids', () => {
    assert.deepEqual(sellingPlansForVariant(groups, { selling_plan_ids: ['gid://shopify/SellingPlan/3'] }).map(p => p.name), ['Plan 3']);
    assert.deepEqual(sellingPlansForVariant(groups, { selling_plan_ids: [] }), []);
    assert.equal(sellingPlansForVariant(groups, {}).length, 3);
});

test('the price adjustment is applied to the price', () => {
    assert.equal(sellingPlanPrice(plan(1, 'PERCENTAGE', 10), '23.00'), 20.7);
    assert.equal(sellingPlanPrice(plan(1, 'FIXED_AMOUNT', 5), '23.00'), 18);
    assert.equal(sellingPlanPrice(plan(1, 'FIXED_AMOUNT', 50), '23.00'), 0);
    assert.equal(sellingPlanPrice(plan(1, 'PRICE', 15), '23.00'), 15);
    assert.equal(sellingPlanPrice({ pricing_policies: [] }, '23.00'), 23);
    assert.equal(sellingPlanPrice(plan(1, 'PERCENTAGE', 10), 'nope'), null);
});

test('only the first delivery’s policy changes the price', () => {
    assert.equal(sellingPlanPrice(plan(1, 'PERCENTAGE', 10, 3), '20.00'), 20);

    const tiered = { pricing_policies: [
        { adjustment_type: 'PERCENTAGE', adjustment_value: 20, after_cycle: 3 },
        { adjustment_type: 'PERCENTAGE', adjustment_value: 10, after_cycle: 0 },
    ] };

    assert.equal(initialPricingPolicy(tiered).adjustment_value, 10);
    assert.equal(sellingPlanPrice(tiered, '20.00'), 18);
});

test('fixed amounts aren’t applied to prices in another currency', () => {
    assert.equal(sellingPlanPrice(plan(1, 'FIXED_AMOUNT', 5), '23.00', { inShopCurrency: false }), null);
    assert.equal(sellingPlanPrice(plan(1, 'PRICE', 5), '23.00', { inShopCurrency: false }), null);
    assert.equal(sellingPlanPrice(plan(1, 'PERCENTAGE', 50), '23.00', { inShopCurrency: false }), 11.5);
});

test('a market’s imported plan price is used for every adjustment type, with the maths as a fallback', () => {
    const variant = { market_data: { IE: { price: '30.00', selling_plans: {
        'gid://shopify/SellingPlan/1': { price: '26.50' },
        'gid://shopify/SellingPlan/2': { price: '24.00' },
    } } } };

    assert.equal(marketSellingPlanPrice(variant, 'IE', 'gid://shopify/SellingPlan/2'), 24);
    assert.equal(marketSellingPlanPrice(variant, 'GB', 'gid://shopify/SellingPlan/2'), null);

    // percentage (the stored price wins over 10% off 30) and fixed amount
    assert.equal(sellingPlanPrice(plan(1, 'PERCENTAGE', 10), '30.00', { inShopCurrency: false, variant, market: 'IE' }), 26.5);
    assert.equal(sellingPlanPrice(plan(2, 'FIXED_AMOUNT', 5), '30.00', { inShopCurrency: false, variant, market: 'IE' }), 24);

    // nothing stored for the plan: percentage maths, nothing for fixed types
    assert.equal(sellingPlanPrice(plan(3, 'PERCENTAGE', 10), '30.00', { inShopCurrency: false, variant, market: 'IE' }), 27);
    assert.equal(sellingPlanPrice(plan(4, 'PRICE', 5), '30.00', { inShopCurrency: false, variant, market: 'IE' }), null);
});

test('sellingPlanLabel uses the plan options, for imported and Storefront plans', () => {
    assert.equal(sellingPlanLabel({ name: 'Deliver every 2 months, £3.00 off', options: ['Deliver every 2 months'] }), 'Deliver every 2 months');
    assert.equal(sellingPlanLabel({ name: 'x', options: [{ name: 'Delivery every', value: 'Deliver every month' }] }), 'Deliver every month');
    assert.equal(sellingPlanLabel({ name: 'Monthly', options: [] }), 'Monthly');
});
