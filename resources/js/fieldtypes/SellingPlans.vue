<template>
    <div>
        <p v-if="! groups.length" class="text-sm text-gray-500">No selling plans</p>

        <div v-for="group in groups" :key="group.id" class="mb-4 last:mb-0">
            <div class="mb-2 flex items-center gap-2">
                <span class="text-sm font-medium">{{ group.name }}</span>
                <ui-badge v-if="group.app_id" :text="group.app_id" />
            </div>

            <ui-table>
                <ui-table-columns>
                    <ui-table-column>Plan</ui-table-column>
                    <ui-table-column>Delivery</ui-table-column>
                    <ui-table-column>Pricing</ui-table-column>
                </ui-table-columns>
                <ui-table-rows>
                    <ui-table-row v-for="plan in group.selling_plans" :key="plan.id">
                        <ui-table-cell>
                            {{ plan.name }}
                            <div v-if="plan.description" class="text-xs text-gray-500">{{ plan.description }}</div>
                        </ui-table-cell>
                        <ui-table-cell class="text-xs">{{ label(plan, 'delivery') }}</ui-table-cell>
                        <ui-table-cell class="text-xs">{{ label(plan, 'pricing') }}</ui-table-cell>
                    </ui-table-row>
                </ui-table-rows>
            </ui-table>
        </div>
    </div>
</template>

<script>
import { FieldtypeMixin as Fieldtype } from '@statamic/cms';

export default {
    mixins: [Fieldtype],

    computed: {
        groups() {
            return Array.isArray(this.value) ? this.value : [];
        },
    },

    methods: {
        label(plan, key) {
            return this.meta?.labels?.[plan.id]?.[key] ?? '';
        },
    },
};
</script>
