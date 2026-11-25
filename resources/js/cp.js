import Variants from './fieldtypes/Variants.vue'
import DisabledText from './fieldtypes/DisabledText.vue'
import SellingPlans from './fieldtypes/SellingPlans.vue'
import Dashboard from './pages/Dashboard.vue'
import Activity from './pages/Activity.vue'

Statamic.booting(() => {
  Statamic.$components.register('variants-fieldtype', Variants)
  Statamic.$components.register('disabled_text-fieldtype', DisabledText)
  Statamic.$components.register('selling_plans-fieldtype', SellingPlans)

  // hides the product blueprint's Subscriptions fields (and so the section) unless selling plans are enabled
  Statamic.$conditions.add('shopifySellingPlansEnabled', () => Boolean(Statamic.$config.get('shopify')?.sellingPlans))

  Statamic.$inertia.register('shopify::Dashboard', Dashboard)
  Statamic.$inertia.register('shopify::Activity', Activity)
});
