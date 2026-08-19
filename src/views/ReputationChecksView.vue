<template>
    <ReputationPageShell needs="domain">
        <template #default>
            <DeliverabilityChecks />
        </template>
    </ReputationPageShell>
</template>

<script>
import ReputationPageShell  from '@/components/ReputationPageShell.vue'
import DeliverabilityChecks from '@/components/DeliverabilityChecks.vue'

export default {
    name: 'ReputationChecksView',
    components: { ReputationPageShell, DeliverabilityChecks },
}
</script>
