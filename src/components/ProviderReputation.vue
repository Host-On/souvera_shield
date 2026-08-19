<template>
    <section class="souvera-section" data-testid="reputation-providers">
        <header class="souvera-section__header">
            <div>
                <h2 class="souvera-heading-2">{{ t('Provider reputation') }}</h2>
                <p class="souvera-section__sub">{{ t('How Google, Microsoft, Yahoo and GMX/Web.de authenticate your mail – computed from the DMARC reports each provider sent for this domain.') }}</p>
            </div>
        </header>

        <div v-if="loading" class="loading-row"><NcLoadingIcon :size="32" /></div>

        <NcEmptyContent v-else-if="error"
                        :name="t('Provider reputation could not be loaded')"
                        :description="error"
                        data-testid="reputation-providers-error">
            <template #icon><AlertCircleOutline :size="40" /></template>
            <template #action>
                <NcButton type="secondary" data-testid="reputation-providers-retry" @click="load">
                    {{ t('Try again') }}
                </NcButton>
            </template>
        </NcEmptyContent>

        <NcEmptyContent v-else-if="!hasData"
                        :name="t('Not enough data yet')"
                        :description="t('No DMARC reports were received for the selected period. Reports from the providers usually arrive within 24–48 hours after mail has been sent to them.')"
                        data-testid="reputation-providers-empty">
            <template #icon><Web :size="40" /></template>
        </NcEmptyContent>

        <div v-else class="provider-grid">
            <div v-for="p in visibleProviders" :key="p.key" class="provider-card" :data-testid="`reputation-provider-${p.key}`">
                <div class="provider-card__head">
                    <strong>{{ label(p) }}</strong>
                    <span :class="['souvera-badge', verdictClass(p.verdict)]">{{ verdictLabel(p.verdict) }}</span>
                </div>
                <template v-if="p.messages > 0">
                    <div class="provider-card__stats">
                        <div><span class="souvera-muted">{{ t('Messages') }}</span><strong>{{ Number(p.messages).toLocaleString() }}</strong></div>
                        <div><span class="souvera-muted">{{ t('DKIM') }}</span><PercentBadge :pct="p.dkimPassRate" /></div>
                        <div><span class="souvera-muted">{{ t('SPF') }}</span><PercentBadge :pct="p.spfPassRate" /></div>
                    </div>
                    <p v-if="p.lastReportAt" class="provider-card__meta souvera-muted">
                        {{ t('Last report:') }} {{ new Date(p.lastReportAt * 1000).toLocaleDateString() }}
                    </p>
                </template>
                <p v-else class="provider-card__empty souvera-muted">
                    {{ t('No reports from this provider in the selected period.') }}
                </p>
            </div>
        </div>
    </section>
</template>

<script>
import NcLoadingIcon  from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcButton       from '@nextcloud/vue/components/NcButton'
import PercentBadge   from '@/components/PercentBadge.vue'

import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import Web                from 'vue-material-design-icons/Web.vue'

import api from '@/services/api'
import { t } from '@/services/i18n'

export default {
    name: 'ProviderReputation',
    components: { NcLoadingIcon, NcEmptyContent, NcButton, PercentBadge, AlertCircleOutline, Web },
    props: {
        days: { type: Number, default: 30 },
        reloadKey: { type: Number, default: 0 },
    },
    setup() { return { t } },
    data() {
        return { loading: true, providers: [], error: null }
    },
    computed: {
        visibleProviders() {
            // Always show the four big providers; "other" only when it has data.
            return this.providers.filter(p => p.key !== 'other' || p.messages > 0)
        },
        hasData() {
            return this.visibleProviders.some(p => p.messages > 0)
        },
    },
    watch: {
        days() { this.load() },
        reloadKey() { this.load() },
    },
    async mounted() { await this.load() },
    methods: {
        label(p) {
            return {
                google:    t('Google (Gmail)'),
                microsoft: t('Microsoft (Outlook/Hotmail)'),
                yahoo:     t('Yahoo/AOL'),
                gmx_webde: t('GMX/Web.de'),
                other:     t('Other providers'),
            }[p.key] || p.label
        },
        verdictClass(v) {
            return { ok: 'souvera-badge--ok', warn: 'souvera-badge--warn', critical: 'souvera-badge--err' }[v] || ''
        },
        verdictLabel(v) {
            return { ok: t('Good'), warn: t('At risk'), critical: t('Critical'), nodata: t('No data') }[v] || v
        },
        async load() {
            this.loading = true
            this.error = null
            try {
                const data = await api.get('/api/reputation/providers', { days: this.days })
                this.providers = Array.isArray(data?.providers) ? data.providers : []
            } catch (e) {
                this.error = api.errorMessage(e)
                this.providers = []
            } finally {
                this.loading = false
            }
        },
    },
}
</script>

<style scoped>
.loading-row { display: flex; justify-content: center; padding: 32px; }
.provider-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
.provider-card {
    border: 1px solid var(--color-border, var(--color-background-dark));
    border-radius: var(--border-radius-large, 12px);
    padding: 16px;
    background: var(--color-main-background);
}
.provider-card__head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 12px; }
.provider-card__head strong { font-size: .92rem; }
.provider-card__stats { display: flex; flex-direction: column; gap: 6px; }
.provider-card__stats > div { display: flex; align-items: center; justify-content: space-between; font-size: .88rem; }
.provider-card__meta { font-size: .76rem; margin: 10px 0 0; }
.provider-card__empty { font-size: .84rem; margin: 0; }
</style>
