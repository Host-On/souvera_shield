<template>
    <section class="souvera-section" data-testid="reputation-incidents">
        <header class="souvera-section__header">
            <div>
                <h2 class="souvera-heading-2">{{ t('Reputation incidents') }}</h2>
                <p class="souvera-section__sub">{{ t('Automatically detected deliverability problems with history, cause, affected domain/IPs and the executed measures.') }}</p>
            </div>
            <div class="souvera-section__actions">
                <NcButton type="primary" :disabled="analyzing"
                          data-testid="reputation-incidents-analyze-btn"
                          @click="runAnalysis">
                    {{ analyzing ? t('Analyzing…') : t('Run analysis now') }}
                </NcButton>
                <div class="segmented" role="tablist" data-testid="reputation-incidents-filter">
                    <button v-for="opt in filters" :key="opt.id"
                            type="button"
                            class="segmented__btn"
                            :class="{ 'is-active': filter === opt.id }"
                            :data-testid="`reputation-incidents-filter-${opt.id}`"
                            @click="filter = opt.id; currentPage = 1">
                        {{ opt.label }}
                    </button>
                </div>
            </div>
        </header>

        <div v-if="loading" class="loading-row"><NcLoadingIcon :size="32" /></div>

        <NcEmptyContent v-else-if="!filtered.length"
                        :name="filter === 'open' ? t('No open incidents – your reputation looks healthy.') : t('No incidents recorded yet.')"
                        :description="emptyDescription"
                        data-testid="reputation-incidents-empty">
            <template #icon><ShieldCheck /></template>
        </NcEmptyContent>

        <div v-else class="incidents-table-wrap">
            <div class="incidents-table-scroll">
                <table class="incidents-table" :aria-label="t('Reputation incidents')">
                    <thead><tr>
                        <th class="col-sev">{{ t('Severity') }}</th>
                        <th>{{ t('Incident') }}</th>
                        <th class="col-cat">{{ t('Category') }}</th>
                        <th class="col-time">{{ t('Updated') }}</th>
                        <th class="col-status">{{ t('Status') }}</th>
                        <th class="col-action"><span class="sr-only">{{ t('Action') }}</span></th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="inc in paginatedIncidents" :key="inc.id" data-testid="reputation-incident-row">
                            <td class="col-sev" :data-label="t('Severity')"><span :class="['souvera-badge', sevClass(inc.severity)]">{{ sevLabel(inc.severity) }}</span></td>
                            <td class="col-title" :data-label="t('Incident')"><span class="incidents-trunc" :title="inc.title">{{ inc.title }}</span></td>
                            <td class="col-cat souvera-muted" :data-label="t('Category')">{{ catLabel(inc.category) }}</td>
                            <td class="col-time souvera-muted" :data-label="t('Updated')">{{ fmtDate(inc.updated_at) }}</td>
                            <td class="col-status" :data-label="t('Status')">
                                <span :class="['souvera-badge', inc.status === 'open' ? 'souvera-badge--warn' : 'souvera-badge--ok']">
                                    {{ inc.status === 'open' ? t('Open') : t('Resolved') }}
                                </span>
                            </td>
                            <td class="col-action">
                                <NcButton type="tertiary" :aria-label="t('Details')" :title="t('Details')"
                                          data-testid="reputation-incident-details-btn"
                                          @click="openDetails(inc)">
                                    <template #icon><InformationOutline :size="20" /></template>
                                </NcButton>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pager
                :page="currentPage"
                :page-count="pageCount"
                :total="filtered.length"
                :per-page="pageSize"
                testid-prefix="reputation-incidents-pager"
                @update:page="currentPage = $event" />
        </div>

        <NcDialog v-if="details"
                  :open="true"
                  :name="details.title"
                  size="normal"
                  data-testid="reputation-incident-dialog"
                  @update:open="details = null">
            <div class="incident-dialog">
                <div class="incident-dialog__badges">
                    <span :class="['souvera-badge', sevClass(details.severity)]">{{ sevLabel(details.severity) }}</span>
                    <span class="souvera-badge">{{ catLabel(details.category) }}</span>
                    <span :class="['souvera-badge', details.status === 'open' ? 'souvera-badge--warn' : 'souvera-badge--ok']">
                        {{ details.status === 'open' ? t('Open') : t('Resolved') }}
                    </span>
                </div>

                <h4>{{ t('What happened') }}</h4>
                <p data-testid="reputation-incident-description">{{ details.description }}</p>

                <h4>{{ t('How to fix') }}</h4>
                <p>{{ details.recommendation }}</p>

                <h4>{{ t('Affected') }}</h4>
                <p>
                    {{ t('Domain:') }} <code>{{ details.domain }}</code>
                    <template v-if="affectedIps.length"> · IPs: <code>{{ affectedIps.join(', ') }}</code></template>
                </p>

                <h4>{{ t('History & measures') }}</h4>
                <ul class="incident-dialog__measures" data-testid="reputation-incident-measures">
                    <li v-for="(m, i) in details.measures" :key="i">
                        <span class="souvera-muted">{{ fmtDateTime(m.ts) }}</span>
                        <strong>{{ measureLabel(m.action) }}</strong>
                        <span v-if="m.actor && m.actor !== 'system'">({{ m.actor }})</span>
                        <span v-if="m.note"> – {{ m.note }}</span>
                    </li>
                </ul>
            </div>
            <template #actions>
                <NcButton v-if="details.status === 'open'" type="primary" :disabled="resolving"
                          data-testid="reputation-incident-resolve-btn"
                          @click="resolveIncident(details)">
                    {{ resolving ? t('Saving…') : t('Mark as resolved') }}
                </NcButton>
                <NcButton type="secondary" @click="details = null">{{ t('Close') }}</NcButton>
            </template>
        </NcDialog>
    </section>
</template>

<script>
import NcButton       from '@nextcloud/vue/components/NcButton'
import NcDialog       from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon  from '@nextcloud/vue/components/NcLoadingIcon'
import ShieldCheck        from 'vue-material-design-icons/ShieldCheck.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'

import { showSuccess, showError } from '@nextcloud/dialogs'
import Pager from '@/components/Pager.vue'
import api from '@/services/api'
import { t } from '@/services/i18n'

export default {
    name: 'ReputationIncidents',
    components: { NcButton, NcDialog, NcEmptyContent, NcLoadingIcon, ShieldCheck, InformationOutline, Pager },
    props: { reloadKey: { type: Number, default: 0 } },
    setup() { return { t } },
    data() {
        return {
            loading: true,
            incidents: [],
            filter: 'open',
            currentPage: 1,
            pageSize: 20,
            details: null,
            resolving: false,
            analyzing: false,
            lastAnalysisAt: null,
        }
    },
    computed: {
        filters() {
            return [
                { id: 'open',     label: t('Open') },
                { id: 'resolved', label: t('Resolved') },
                { id: 'all',      label: t('All') },
            ]
        },
        filtered() {
            if (this.filter === 'all') return this.incidents
            return this.incidents.filter(i => i.status === this.filter)
        },
        pageCount() { return Math.max(1, Math.ceil(this.filtered.length / this.pageSize)) },
        paginatedIncidents() {
            const start = (this.currentPage - 1) * this.pageSize
            let slice = this.filtered.slice(start, start + this.pageSize)
            if (slice.length === 0 && this.currentPage > 1) {
                this.currentPage = Math.max(1, this.pageCount)
                slice = this.filtered.slice((this.currentPage - 1) * this.pageSize, this.currentPage * this.pageSize)
            }
            return slice
        },
        affectedIps() {
            const ev = this.details?.evidence || {}
            const ips = []
            const target = ev?.check?.observed?.target || ev?.check?.observed?.ip
            if (target && /^\d/.test(String(target))) ips.push(String(target))
            return ips
        },
        emptyDescription() {
            const auto = t('Incidents are detected automatically after every mail test and during the daily analysis.')
            const last = this.lastAnalysisAt
                ? t('Last analysis: {time}', { time: new Date(this.lastAnalysisAt * 1000).toLocaleString() })
                : t('No analysis has run yet.')
            return `${auto} ${last}`
        },
    },
    watch: {
        reloadKey() { this.load() },
    },
    async mounted() { await this.load() },
    methods: {
        sevClass(s) {
            return { critical: 'souvera-badge--err', warning: 'souvera-badge--warn' }[s] || ''
        },
        sevLabel(s) {
            return { critical: t('Critical'), warning: t('Warning'), info: t('Info') }[s] || s
        },
        catLabel(c) {
            return {
                blacklist: t('Blacklist'),
                auth:      t('Authentication'),
                anomaly:   t('Anomaly'),
                abuse:     t('Abuse'),
                infra:     t('Infrastructure'),
                mail_test: t('Mail test'),
            }[c] || c
        },
        measureLabel(a) {
            return {
                detected:      t('Detected'),
                reopened:      t('Re-detected'),
                auto_resolved: t('Auto-resolved'),
                resolved:      t('Resolved'),
            }[a] || a
        },
        fmtDate(ts) { return ts ? new Date(ts * 1000).toLocaleDateString() : '' },
        fmtDateTime(ts) { return ts ? new Date(ts * 1000).toLocaleString() : '' },
        openDetails(inc) { this.details = inc },
        async load() {
            this.loading = true
            try {
                const data = await api.get('/api/reputation/incidents', { status: 'all' })
                this.incidents = Array.isArray(data?.incidents) ? data.incidents : []
                this.lastAnalysisAt = data?.last_analysis_at || null
            } catch (e) {
                showError(api.errorMessage(e))
                this.incidents = []
            } finally {
                this.loading = false
            }
        },
        async runAnalysis() {
            this.analyzing = true
            try {
                const res = await api.post('/api/reputation/analyze')
                showSuccess(t('Analysis completed – {open} open incidents.', { open: res?.incidents?.open ?? 0 }))
                await this.load()
            } catch (e) {
                showError(api.errorMessage(e))
            } finally {
                this.analyzing = false
            }
        },
        async resolveIncident(inc) {
            this.resolving = true
            try {
                const updated = await api.post(`/api/reputation/incidents/${inc.id}/resolve`)
                showSuccess(t('Incident marked as resolved.'))
                this.details = updated
                await this.load()
            } catch (e) {
                showError(api.errorMessage(e))
            } finally {
                this.resolving = false
            }
        },
    },
}
</script>

<style scoped>
.loading-row { display: flex; justify-content: center; padding: 32px; }

.incidents-table-wrap {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large, 12px);
    overflow: hidden;
    margin-bottom: 12px;
}

.incidents-table-scroll {
    overflow-x: auto;
}

.incidents-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
    color: var(--color-main-text);
    min-width: 600px;
}

.incidents-table thead th {
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

.incidents-table td {
    padding: 10px 16px;
    border-bottom: 1px solid var(--color-border);
    vertical-align: middle;
    word-break: break-word;
}

.incidents-table tbody tr {
    transition: background-color .15s ease;
}
.incidents-table tbody tr:hover {
    background: var(--color-background-hover);
}
.incidents-table tbody tr:last-child td {
    border-bottom: none;
}

.col-sev    { width: 7rem; }
.col-cat    { width: 9rem; }
.col-time   { width: 7rem; white-space: nowrap; font-variant-numeric: tabular-nums; }
.col-status { width: 7rem; }
.col-action { width: 3.5rem; text-align: right; }
.col-title  { min-width: 12rem; }

.incidents-trunc {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 100%;
}

.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }

.segmented { display: inline-flex; border: 1px solid var(--color-border, var(--color-background-dark)); border-radius: var(--border-radius-large, 12px); overflow: hidden; background: var(--color-main-background); }
.segmented__btn {
    background: transparent; border: 0; padding: 8px 16px; font-size: .85rem; font-weight: 600;
    color: var(--color-text-maxcontrast); cursor: pointer;
    border-right: 1px solid var(--color-border, var(--color-background-dark));
}
.segmented__btn:last-child { border-right: 0; }
.segmented__btn:hover:not(.is-active) { background: var(--color-background-hover); color: var(--color-main-text); }
.segmented__btn.is-active { background: var(--color-primary-element); color: var(--color-primary-element-text); }

.incident-dialog h4 { margin: 16px 0 4px; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: var(--color-text-maxcontrast); }
.incident-dialog p { margin: 0; font-size: .9rem; }
.incident-dialog code { background: var(--color-background-dark); padding: 1px 6px; border-radius: 4px; }
.incident-dialog__badges { display: flex; gap: 8px; }
.incident-dialog__measures { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; font-size: .85rem; }

@media (max-width: 640px) {
    .incidents-table { font-size: .82rem; }
    .incidents-table th,
    .incidents-table td { padding: 8px 10px; }
}
</style>
