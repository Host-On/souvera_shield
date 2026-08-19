<template>
    <ReputationPageShell needs="verified">
        <template #default="{ domain }">
            <RangeSwitcher v-model="days" />
            <DmarcSources :days="days" />
            <ReputationReports :provider-domain-id="domain.provider_domain_id" />
        </template>
    </ReputationPageShell>
</template>

<script>
import ReputationPageShell from '@/components/ReputationPageShell.vue'
import RangeSwitcher       from '@/components/RangeSwitcher.vue'
import DmarcSources        from '@/components/DmarcSources.vue'
import ReputationReports   from '@/components/ReputationReports.vue'

export default {
    name: 'ReputationSourcesView',
    components: { ReputationPageShell, RangeSwitcher, DmarcSources, ReputationReports },
    data() {
        return { days: 30 }
    },
}
</script>
