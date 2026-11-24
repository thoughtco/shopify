import assert from 'node:assert/strict';
import { test } from 'node:test';
import { allSellingPlans, initialPricingPolicy, sellingPlanPrice, sellingPlansForVariant } from '../../resources/js/shopify/selling-plans.js';

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
