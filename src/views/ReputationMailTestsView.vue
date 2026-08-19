<template>
    <ReputationPageShell needs="domain">
        <template #default="{ domain }">
            <MailTests :domain="domain" />
        </template>
    </ReputationPageShell>
</template>

<script>
import ReputationPageShell from '@/components/ReputationPageShell.vue'
import MailTests           from '@/components/MailTests.vue'

export default {
    name: 'ReputationMailTestsView',
    components: { ReputationPageShell, MailTests },
}
</script>
