<template>
    <section class="souvera-section" data-testid="reputation-mailtests">
        <header class="souvera-section__header">
            <div>
                <h2 class="souvera-heading-2">{{ t('Mail test') }}</h2>
                <p class="souvera-section__sub">{{ t('Automated weekly reputation check every Sunday. You can also trigger a test on demand.') }}</p>
            </div>
            <div class="souvera-section__actions">
                <NcButton type="primary"
                          data-testid="reputation-mailtest-run"
                          :disabled="!canRun || running"
                          @click="onRun">
                    <template v-if="running" #icon><NcLoadingIcon :size="18" /></template>
                    <template v-else #icon><EmailFast :size="20" /></template>
                    {{ t('Run mail test now') }}
                </NcButton>
            </div>
        </header>

        <div v-if="loading" class="loading-row">
            <NcLoadingIcon :size="32" />
        </div>

        <NcEmptyContent v-else-if="!tests.length"
                        :name="t('No mail tests yet.')"
                        data-testid="reputation-mailtests-empty">
            <template #icon><EmailFast /></template>
        </NcEmptyContent>

        <div v-else class="tests-table-wrap">
            <div class="tests-table-scroll">
                <table class="tests-table" :aria-label="t('Mail test history')">
                    <thead><tr>
                        <th class="col-time">{{ t('When') }}</th>
                        <th>{{ t('Status') }}</th>
                        <th class="col-num">{{ t('Score') }}</th>
                        <th class="col-num">{{ t('SPF') }}</th>
                        <th class="col-num">{{ t('DKIM') }}</th>
                        <th class="col-num">{{ t('DMARC') }}</th>
                        <th class="col-trigger">{{ t('Trigger') }}</th>
                        <th class="col-action">{{ t('Action') }}</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="r in tests" :key="r.id" data-testid="reputation-mailtests-row">
                            <td class="col-time" :data-label="t('When')">{{ fmtDateTime(r.created_at) }}</td>
                            <td :data-label="t('Status')"><StatusBadge :status="r.status" :error="r.error" /></td>
                            <td class="col-num" :data-label="t('Score')"><ScoreBadge :score="r.score" :status="r.status" /></td>
                            <td class="col-num" :data-label="t('SPF')"><ResultBadge :value="r.spf"   /></td>
                            <td class="col-num" :data-label="t('DKIM')"><ResultBadge :value="r.dkim"  /></td>
                            <td class="col-num" :data-label="t('DMARC')"><ResultBadge :value="r.dmarc" /></td>
                            <td class="col-trigger" :data-label="t('Trigger')"><span class="souvera-muted">{{ r.trigger_type === 'weekly' ? t('Weekly') : t('Manual') }}</span></td>
                            <td class="col-action" data-label="">
                                <div class="actions">
                                    <NcButton v-if="canRefresh(r)"
                                              type="tertiary"
                                              :aria-label="t('Refresh')"
                                              :title="t('Refresh')"
                                              :data-testid="`reputation-mailtest-refresh-${r.id}`"
                                              @click="onRefresh(r)">
                                        <template #icon><Refresh :size="18" /></template>
                                    </NcButton>
                                    <NcButton type="tertiary"
                                              :aria-label="t('Details')"
                                              :title="t('Details')"
                                              :data-testid="`reputation-mailtest-details-${r.id}`"
                                              @click="onDetails(r)">
                                        <template #icon><InformationOutline :size="18" /></template>
                                    </NcButton>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Details modal -->
        <NcDialog v-if="details.open"
                  :name="t('Mail test details')"
                  :buttons="detailsButtons"
                  data-testid="reputation-mailtest-details-dialog"
                  @update:open="details.open = $event">
            <dl class="details-list">
                <template v-for="row in detailsRows" :key="row[0]">
                    <dt>{{ row[0] }}</dt>
                    <dd>{{ row[1] }}</dd>
                </template>
            </dl>
        </NcDialog>

        <!-- Run-test confirmation modal -->
        <NcDialog v-if="confirm.open"
                  :name="t('Run mail test now?')"
                  :buttons="confirmButtons"
                  data-testid="reputation-mailtest-confirm-dialog"
                  @update:open="confirm.open = $event">
            <p>{{ t('Souvera Shield will send one test e-mail through the mail server and wait for the analysis. Results usually arrive within 30 seconds.') }}</p>
        </NcDialog>
    </section>
</template>

<script>
import NcButton       from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon  from '@nextcloud/vue/components/NcLoadingIcon'
import NcDialog       from '@nextcloud/vue/components/NcDialog'

import EmailFast          from 'vue-material-design-icons/EmailFast.vue'
import Refresh            from 'vue-material-design-icons/Refresh.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'

import StatusBadge  from '@/components/StatusBadge.vue'
import ScoreBadge   from '@/components/ScoreBadge.vue'
import ResultBadge  from '@/components/ResultBadge.vue'

import { showSuccess, showError } from '@nextcloud/dialogs'
import api from '@/services/api'
import { t } from '@/services/i18n'

export default {
    name: 'MailTests',
    components: { NcButton, NcEmptyContent, NcLoadingIcon, NcDialog,
        EmailFast, Refresh, InformationOutline,
        StatusBadge, ScoreBadge, ResultBadge },
    props: { domain: { type: Object, default: null } },
    setup() { return { t } },
    data() {
        return {
            loading: true,
            running: false,
            tests: [],
            confirm: { open: false },
            details: { open: false, row: null },
        }
    },
    computed: {
        canRun() { return !!this.domain?.provider_verified },
        detailsRows() {
            const r = this.details.row
            if (!r) return []
            return [
                [t('Started'),      this.fmtDateTime(r.created_at)],
                [t('Completed'),    this.fmtDateTime(r.completed_at)],
                [t('Status'),       r.status],
                [t('Test ID'),      r.test_id || '—'],
                [t('Test address'), r.test_email || '—'],
                [t('Score'),        r.score !== null && r.score !== undefined ? Number(r.score).toFixed(1) : '—'],
                [t('SPF'),          r.spf   || '—'],
                [t('DKIM'),         r.dkim  || '—'],
                [t('DMARC'),        r.dmarc || '—'],
                [t('Trigger'),      r.trigger_type],
                [t('Triggered by'), r.triggered_by || 'system'],
                [t('Error'),        r.error || '—'],
            ]
        },
        detailsButtons() {
            return [{ label: t('Close'), type: 'secondary', callback: () => { this.details.open = false } }]
        },
        confirmButtons() {
            return [
                { label: t('Cancel'), type: 'secondary', callback: () => { this.confirm.open = false } },
                { label: t('OK'),     type: 'primary',   callback: async () => {
                    this.confirm.open = false
                    await this.runNow()
                } },
            ]
        },
    },
    async mounted() {
        await this.load()
    },
    methods: {
        fmtDateTime(ts) {
            if (!ts) return '—'
            return new Date(Number(ts) * 1000).toLocaleString()
        },
        canRefresh(r) { return r.status === 'pending' || r.status === 'sent' },
        async load() {
            this.loading = true
            try {
                const data = await api.get('/api/dmarc/tests', { limit: 200 })
                this.tests = Array.isArray(data) ? data : (data?.data || data || [])
            } catch (e) {
                showError(api.errorMessage(e))
                this.tests = []
            } finally {
                this.loading = false
            }
        },
        onRun() { this.confirm.open = true },
        async runNow() {
            this.running = true
            try {
                showSuccess(t('Sending test mail…'))
                await api.post('/api/dmarc/domain/test')
                showSuccess(t('Test started – see the history below.'))
                await this.load()
            } catch (e) {
                showError(api.errorMessage(e))
            } finally {
                this.running = false
            }
        },
        async onRefresh(r) {
            try {
                await api.post(`/api/dmarc/tests/${r.id}/refresh`)
                await this.load()
            } catch (e) {
                showError(api.errorMessage(e))
            }
        },
        onDetails(r) {
            this.details = { open: true, row: r }
        },
    },
}
</script>

<style scoped>
.loading-row { display: flex; justify-content: center; padding: 32px; }

.tests-table-wrap {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large, 12px);
    overflow: hidden;
    margin-bottom: 12px;
}

.tests-table-scroll {
    overflow-x: auto;
}

.tests-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
    color: var(--color-main-text);
}

.tests-table thead th {
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

.tests-table td {
    padding: 10px 16px;
    border-bottom: 1px solid var(--color-border);
    vertical-align: middle;
}

.tests-table tbody tr {
    transition: background-color .15s ease;
}
.tests-table tbody tr:hover {
    background: var(--color-background-hover);
}
.tests-table tbody tr:last-child td {
    border-bottom: none;
}

.col-time    { width: 11rem; white-space: nowrap; font-variant-numeric: tabular-nums; }
.col-num     { width: 5.5rem; text-align: center; }
.col-trigger { width: 6rem; }
.col-action  { width: 6rem;  text-align: right; }
.actions { display: inline-flex; gap: 4px; justify-content: flex-end; }
.details-list {
    display: grid;
    grid-template-columns: 10rem 1fr;
    gap: 6px 16px;
    margin: 0;
}
.details-list dt { color: var(--color-text-maxcontrast); font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; }
.details-list dd { margin: 0; color: var(--color-main-text); word-break: break-word; }

@media (max-width: 640px) {
    .tests-table { font-size: .82rem; }
    .tests-table th,
    .tests-table td { padding: 8px 10px; }
}
</style>
