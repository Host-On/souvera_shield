<template>
    <ReputationPageShell needs="domain">
        <template #default>
            <ReputationIncidents />
        </template>
    </ReputationPageShell>
</template>

<script>
import ReputationPageShell from '@/components/ReputationPageShell.vue'
import ReputationIncidents from '@/components/ReputationIncidents.vue'

export default {
    name: 'ReputationIncidentsView',
    components: { ReputationPageShell, ReputationIncidents },
}
</script>
