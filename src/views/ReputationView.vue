<template>
    <div>
        <header class="souvera-section__header">
            <div>
                <h1 class="souvera-heading-1">{{ t('Reputation Management') }}</h1>
                <p class="souvera-section__sub">
                    {{ t('DMARC Analyzer for your workspace domain: aggregate reports, DKIM/SPF statistics and weekly reputation tests.') }}
                </p>
            </div>
        </header>

        <NcNoteCard v-if="providerStatus && !providerStatus.configured" type="warning" data-testid="reputation-provider-status">
            {{ t('The reputation service is not configured yet – reputation checks are disabled. Please contact your hoster.') }}
        </NcNoteCard>

        <ReputationDomain
            :domain="domain"
            :loading="loading"
            @registered="reload"
            @verified="reload"
            @refresh="reload" />

        <template v-if="domain">
            <ReputationScore :days="days" :reload-key="analysisRun" @analyzed="analysisRun++" />
        </template>

        <template v-if="domain && domain.provider_verified">
            <RangeSwitcher v-model="days" />
            <ReputationStats  :days="days" />
            <ReputationCharts :days="days" />
        </template>
    </div>
</template>

<script>
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

import ReputationDomain from '@/components/ReputationDomain.vue'
import ReputationScore  from '@/components/ReputationScore.vue'
import ReputationStats  from '@/components/ReputationStats.vue'
import ReputationCharts from '@/components/ReputationCharts.vue'
import RangeSwitcher    from '@/components/RangeSwitcher.vue'

import { useReputationDomain } from '@/composables/useReputationDomain'
import { t } from '@/services/i18n'

/**
 * "Score & analysis" – the Reputation landing page: domain
 * registration, reputation score, DMARC statistics and charts.
 * Provider breakdown, deliverability checks, sending sources,
 * incidents and mail tests live on their own pages underneath the
 * "Reputation" navigation caption.
 */
export default {
    name: 'ReputationView',
    components: {
        NcNoteCard, ReputationDomain, ReputationScore, ReputationStats,
        ReputationCharts, RangeSwitcher,
    },
    setup() {
        const { domain, providerStatus, loading, reload } = useReputationDomain()
        return { t, domain, providerStatus, loading, reload }
    },
    data() {
        return {
            days: 30,
            analysisRun: 0,
        }
    },
    async mounted() {
        await this.reload()
    },
}
</script>
