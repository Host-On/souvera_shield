<template>
    <section class="souvera-section" data-testid="reputation-stats">
        <header class="souvera-section__header">
            <div>
                <h2 class="souvera-heading-2">{{ t('Statistics') }}</h2>
                <p class="souvera-section__sub">{{ t('Aggregate DMARC/SPF/DKIM statistics from received reports.') }}</p>
            </div>
        </header>

        <div v-if="loading" class="loading-row">
            <NcLoadingIcon :size="32" />
        </div>

        <template v-else>
            <div class="souvera-stats">
                <div class="souvera-stat" data-testid="reputation-stat-messages">
                    <span class="souvera-stat__label">{{ t('Messages') }}</span>
                    <strong class="souvera-stat__value">{{ totalMessages.toLocaleString() }}</strong>
                </div>
                <div class="souvera-stat" data-testid="reputation-stat-reports">
                    <span class="souvera-stat__label">{{ t('Reports') }}</span>
                    <strong class="souvera-stat__value">{{ totalReports.toLocaleString() }}</strong>
                </div>
                <div class="souvera-stat" data-testid="reputation-stat-dkim">
                    <span class="souvera-stat__label">{{ t('DKIM pass') }}</span>
                    <strong class="souvera-stat__value">{{ dkimPct === null ? '—' : dkimPct + '%' }}</strong>
                </div>
                <div class="souvera-stat" data-testid="reputation-stat-spf">
                    <span class="souvera-stat__label">{{ t('SPF pass') }}</span>
                    <strong class="souvera-stat__value">{{ spfPct === null ? '—' : spfPct + '%' }}</strong>
                </div>
            </div>

            <h3 class="souvera-heading-2 top-senders-heading">{{ t('Top senders') }}</h3>

            <NcEmptyContent v-if="!topSenders.length" :name="t('No sender data available for this period.')">
                <template #icon><AccountGroup /></template>
            </NcEmptyContent>

            <template v-else>
                <div class="top-table-wrap">
                    <div class="top-table-scroll">
                        <table class="top-table" :aria-label="t('Top senders')">
                            <thead><tr>
                                <th>{{ t('Organization') }}</th>
                                <th class="col-num">{{ t('Messages') }}</th>
                                <th class="col-num">{{ t('DKIM') }}</th>
                                <th class="col-num">{{ t('SPF') }}</th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="row in pagedTopSenders" :key="(row.organization || row.orgName) + '-' + row.messages">
                                    <td :data-label="t('Organization')">{{ row.organization || row.orgName || '?' }}</td>
                                    <td class="col-num" :data-label="t('Messages')">{{ Number(row.messages || 0).toLocaleString() }}</td>
                                    <td class="col-num" :data-label="t('DKIM')"><PercentBadge :pct="row.dkimPassRate" /></td>
                                    <td class="col-num" :data-label="t('SPF')"><PercentBadge :pct="row.spfPassRate" /></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <Pager
                        :page="topPage"
                        :page-count="topPageCount"
                        :total="topSenders.length"
                        :per-page="TOP_PER_PAGE"
                        testid-prefix="reputation-topsenders-pager"
                        @update:page="topPage = $event" />
                </div>
            </template>
        </template>
    </section>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon  from '@nextcloud/vue/components/NcLoadingIcon'
import AccountGroup   from 'vue-material-design-icons/AccountGroup.vue'

import PercentBadge from '@/components/PercentBadge.vue'
import Pager        from '@/components/Pager.vue'

import { showError } from '@nextcloud/dialogs'

import api from '@/services/api'
import { t } from '@/services/i18n'

const TOP_PER_PAGE = 5

export default {
    name: 'ReputationStats',
    components: { NcEmptyContent, NcLoadingIcon, AccountGroup, PercentBadge, Pager },
    props: { days: { type: Number, default: 30 } },
    setup() { return { t, TOP_PER_PAGE } },
    data() {
        return {
            loading: true,
            stats: null,
            topPage: 1,
        }
    },
    computed: {
        totalMessages() { return Number(this.stats?.totalMessages || 0) },
        totalReports()  { return Number(this.stats?.totalReports  || 0) },
        dkimPct()       { return clamp(this.stats?.dkimPassRate) },
        spfPct()        { return clamp(this.stats?.spfPassRate)  },
        topSenders() {
            return Array.isArray(this.stats?.topSenders) ? this.stats.topSenders : []
        },
        topPageCount() { return Math.max(1, Math.ceil(this.topSenders.length / TOP_PER_PAGE)) },
        pagedTopSenders() {
            const start = (this.topPage - 1) * TOP_PER_PAGE
            return this.topSenders.slice(start, start + TOP_PER_PAGE)
        },
    },
    watch: {
        days: {
            immediate: true,
            handler() {
                this.topPage = 1
                this.load()
            },
        },
    },
    methods: {
        async load() {
            this.loading = true
            try {
                this.stats = await api.get('/api/dmarc/domain/stats', { days: this.days })
            } catch (e) {
                showError(api.errorMessage(e))
                this.stats = null
            } finally {
                this.loading = false
            }
        },
    },
}

function clamp(v) {
    const n = Number(v)
    if (!Number.isFinite(n)) return null
    return Math.max(0, Math.min(100, Math.round(n)))
}
</script>

<style scoped>
.loading-row { display: flex; justify-content: center; padding: 32px; }
.top-senders-heading { margin-top: 20px; margin-bottom: 8px; font-size: .82rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--color-text-maxcontrast); }

.top-table-wrap {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large, 12px);
    overflow: hidden;
    margin-bottom: 12px;
}

.top-table-scroll {
    overflow-x: auto;
}

.top-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
    color: var(--color-main-text);
}

.top-table thead th {
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

.top-table td {
    padding: 10px 16px;
    border-bottom: 1px solid var(--color-border);
    vertical-align: middle;
}

.top-table tbody tr {
    transition: background-color .15s ease;
}
.top-table tbody tr:hover {
    background: var(--color-background-hover);
}
.top-table tbody tr:last-child td {
    border-bottom: none;
}

.col-num { width: 5.5rem; text-align: center; white-space: nowrap; }

@media (max-width: 640px) {
    .top-table { font-size: .82rem; }
    .top-table th,
    .top-table td { padding: 8px 10px; }
}
</style>
