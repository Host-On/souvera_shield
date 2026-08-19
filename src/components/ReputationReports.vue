<template>
    <section class="souvera-section" data-testid="reputation-reports">
        <header class="souvera-section__header">
            <div>
                <h2 class="souvera-heading-2">{{ t('Aggregate reports') }}</h2>
                <p class="souvera-section__sub">{{ t('Individual DMARC aggregate reports (RUA) received for your domain.') }}</p>
            </div>
        </header>

        <div v-if="loading" class="loading-row">
            <NcLoadingIcon :size="32" />
        </div>

        <NcEmptyContent v-else-if="!rows.length"
                        :name="t('No aggregate reports yet. Reports usually arrive within a few days after the DMARC record is live.')"
                        data-testid="reputation-reports-empty">
            <template #icon><FileDocumentOutline /></template>
        </NcEmptyContent>

        <template v-else>
            <div class="reports-table-wrap">
                <div class="reports-table-scroll">
                    <table class="reports-table" :aria-label="t('DMARC aggregate reports')">
                        <thead><tr>
                            <th>{{ t('Reporter') }}</th>
                            <th class="col-time">{{ t('From') }}</th>
                            <th class="col-time">{{ t('To') }}</th>
                            <th class="col-num">{{ t('Messages') }}</th>
                            <th class="col-num">{{ t('DKIM') }}</th>
                            <th class="col-num">{{ t('SPF') }}</th>
                            <th class="col-policy">{{ t('Policy') }}</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="r in rows" :key="r.id || (r.orgName + r.dateRangeBegin)" data-testid="reputation-reports-row">
                                <td>{{ r.orgName || '?' }}</td>
                                <td class="col-time">{{ fmtDate(r.dateRangeBegin) }}</td>
                                <td class="col-time">{{ fmtDate(r.dateRangeEnd) }}</td>
                                <td class="col-num">{{ Number(r.totalMessages || 0).toLocaleString() }}</td>
                                <td class="col-num"><PercentBadge :pct="pctOf(r.passedDkim, r.totalMessages)" /></td>
                                <td class="col-num"><PercentBadge :pct="pctOf(r.passedSpf,  r.totalMessages)" /></td>
                                <td class="col-policy"><span class="souvera-badge">{{ (r.policyP || '?').toString().toUpperCase() }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pager
                    :page="page"
                    :page-count="pageCount"
                    :total="total"
                    :per-page="PER_PAGE"
                    testid-prefix="reputation-reports-pager"
                    @update:page="setPage" />
            </div>
        </template>
    </section>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon  from '@nextcloud/vue/components/NcLoadingIcon'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'

import PercentBadge from '@/components/PercentBadge.vue'
import Pager        from '@/components/Pager.vue'

import { showError } from '@nextcloud/dialogs'
import api from '@/services/api'
import { t } from '@/services/i18n'

const PER_PAGE = 10

export default {
    name: 'ReputationReports',
    components: { NcEmptyContent, NcLoadingIcon, FileDocumentOutline, PercentBadge, Pager },
    props: { providerDomainId: { type: String, default: '' } },
    setup() { return { t, PER_PAGE } },
    data() {
        return {
            loading: true,
            page: 1,
            rows: [],
            total: 0,
        }
    },
    computed: {
        pageCount() { return Math.max(1, Math.ceil(this.total / PER_PAGE)) },
    },
    async mounted() { await this.load() },
    methods: {
        fmtDate(v) { return v ? new Date(v).toLocaleDateString() : '' },
        pctOf(passed, total) {
            const t = Number(total || 0)
            if (t <= 0) return null
            return Math.round((Number(passed || 0) / t) * 100)
        },
        async setPage(p) {
            this.page = p
            await this.load()
        },
        async load() {
            this.loading = true
            try {
                const data = await api.get('/api/dmarc/domain/reports', { page: this.page, limit: PER_PAGE })
                this.rows  = Array.isArray(data?.reports) ? data.reports : []
                this.total = Number(data?.total ?? data?.totalCount ?? this.rows.length)
            } catch (e) {
                showError(api.errorMessage(e))
                this.rows = []
                this.total = 0
            } finally {
                this.loading = false
            }
        },
    },
}
</script>

<style scoped>
.loading-row { display: flex; justify-content: center; padding: 32px; }

.reports-table-wrap {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large, 12px);
    overflow: hidden;
    margin-bottom: 12px;
}

.reports-table-scroll {
    overflow-x: auto;
}

.reports-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
    color: var(--color-main-text);
}

.reports-table thead th {
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

.reports-table td {
    padding: 10px 16px;
    border-bottom: 1px solid var(--color-border);
    vertical-align: middle;
}

.reports-table tbody tr {
    transition: background-color .15s ease;
}
.reports-table tbody tr:hover {
    background: var(--color-background-hover);
}
.reports-table tbody tr:last-child td {
    border-bottom: none;
}

.col-time    { width: 8rem;   white-space: nowrap; }
.col-num     { width: 6rem;   text-align: center; }
.col-policy  { width: 6rem;   text-align: center; }

@media (max-width: 640px) {
    .reports-table { font-size: .82rem; }
    .reports-table th,
    .reports-table td { padding: 8px 10px; }
}
</style>
