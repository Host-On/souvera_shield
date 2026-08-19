<template>
    <ReputationPageShell needs="verified">
        <template #default>
            <RangeSwitcher v-model="days" />
            <ProviderReputation :days="days" />
        </template>
    </ReputationPageShell>
</template>

<script>
import ReputationPageShell from '@/components/ReputationPageShell.vue'
import RangeSwitcher       from '@/components/RangeSwitcher.vue'
import ProviderReputation  from '@/components/ProviderReputation.vue'

export default {
    name: 'ReputationProvidersView',
    components: { ReputationPageShell, RangeSwitcher, ProviderReputation },
    data() {
        return { days: 30 }
    },
}
</script>
