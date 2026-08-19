<template>
    <section class="souvera-section" data-testid="reputation-sources">
        <header class="souvera-section__header">
            <div>
                <h2 class="souvera-heading-2">{{ t('Sending sources') }}</h2>
                <p class="souvera-section__sub">{{ t('Sources sending mail in the name of this domain, classified from real DMARC report data: legitimate, unknown or potentially abusive.') }}</p>
            </div>
        </header>

        <div v-if="loading" class="loading-row"><NcLoadingIcon :size="32" /></div>

        <template v-else>
            <NcNoteCard v-for="(a, i) in anomalies" :key="'a' + i"
                        :type="a.type === 'volume_spike' ? 'error' : 'warning'"
                        data-testid="reputation-anomaly">
                <template v-if="a.type === 'volume_spike'">
                    {{ t('Unusual volume spike on {day}: {messages} messages (baseline ~{baseline}). This can indicate a compromised account – check the incidents below.', { day: a.day, messages: a.messages, baseline: a.baseline }) }}
                </template>
                <template v-else>
                    {{ t('"{org}" sent {messages} messages without passing SPF or DKIM – possible abuse of your domain.', { org: a.organization, messages: a.messages }) }}
                </template>
            </NcNoteCard>

            <NcEmptyContent v-if="!sources.length"
                            :name="t('No sender data available for this period.')"
                            data-testid="reputation-sources-empty">
                <template #icon><ServerNetwork /></template>
            </NcEmptyContent>

            <template v-else>
                <div class="sources-table-wrap">
                    <div class="sources-table-scroll">
                        <table class="sources-table" :aria-label="t('Sending sources')">
                            <thead><tr>
                                <th>{{ t('Source') }}</th>
                                <th class="col-num">{{ t('Messages') }}</th>
                                <th class="col-num">{{ t('DKIM') }}</th>
                                <th class="col-num">{{ t('SPF') }}</th>
                                <th class="col-class">{{ t('Classification') }}</th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="s in sources" :key="s.organization" data-testid="reputation-source-row">
                                    <td>{{ s.organization }}</td>
                                    <td class="col-num">{{ Number(s.messages || 0).toLocaleString() }}</td>
                                    <td class="col-num"><PercentBadge :pct="s.dkimPassRate" /></td>
                                    <td class="col-num"><PercentBadge :pct="s.spfPassRate" /></td>
                                    <td class="col-class">
                                        <span :class="['souvera-badge', classBadge(s.classification)]">{{ classLabel(s.classification) }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <p class="sources-note souvera-muted">
                    {{ t('Legitimate = passes SPF or DKIM aligned (≥90%). Potentially abusive = fails both with relevant volume. Unknown = mixed results, often forwarders or mailing lists (they break SPF by design).') }}
                </p>
            </template>
        </template>
    </section>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon  from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard     from '@nextcloud/vue/components/NcNoteCard'
import ServerNetwork  from 'vue-material-design-icons/ServerNetwork.vue'

import PercentBadge from '@/components/PercentBadge.vue'

import { showError } from '@nextcloud/dialogs'
import api from '@/services/api'
import { t } from '@/services/i18n'

export default {
    name: 'DmarcSources',
    components: { NcEmptyContent, NcLoadingIcon, NcNoteCard, ServerNetwork, PercentBadge },
    props: {
        days: { type: Number, default: 30 },
        reloadKey: { type: Number, default: 0 },
    },
    setup() { return { t } },
    data() {
        return { loading: true, sources: [], anomalies: [] }
    },
    watch: {
        days() { this.load() },
        reloadKey() { this.load() },
    },
    async mounted() { await this.load() },
    methods: {
        classBadge(c) {
            return { legitimate: 'souvera-badge--ok', abusive: 'souvera-badge--err' }[c] || ''
        },
        classLabel(c) {
            return {
                legitimate: t('Legitimate'),
                unknown:    t('Unknown'),
                abusive:    t('Potentially abusive'),
            }[c] || c
        },
        async load() {
            this.loading = true
            try {
                const data = await api.get('/api/reputation/sources', { days: this.days })
                this.sources = Array.isArray(data?.sources) ? data.sources : []
                this.anomalies = Array.isArray(data?.anomalies) ? data.anomalies : []
            } catch (e) {
                showError(api.errorMessage(e))
                this.sources = []
                this.anomalies = []
            } finally {
                this.loading = false
            }
        },
    },
}
</script>

<style scoped>
.loading-row { display: flex; justify-content: center; padding: 32px; }

.sources-table-wrap {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large, 12px);
    overflow: hidden;
    margin-bottom: 12px;
}

.sources-table-scroll {
    overflow-x: auto;
}

.sources-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
    color: var(--color-main-text);
}

.sources-table thead th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--color-text-maxcontrast);
    background: var(--color-background-dark);
    border-bottom: 1px solid var(--color-border);
}

.sources-table td {
    padding: 10px 16px;
    border-bottom: 1px solid var(--color-border);
    vertical-align: middle;
    word-break: break-word;
}

.sources-table tbody tr {
    transition: background-color .15s ease;
}
.sources-table tbody tr:hover {
    background: var(--color-background-hover);
}
.sources-table tbody tr:last-child td {
    border-bottom: none;
}

.col-num { width: 6rem; text-align: center; white-space: nowrap; }
.col-class { width: 11rem; }
.sources-note { font-size: .78rem; margin-top: 10px; }

@media (max-width: 640px) {
    .sources-table { font-size: .82rem; }
    .sources-table th,
    .sources-table td { padding: 8px 10px; }
}
</style>
