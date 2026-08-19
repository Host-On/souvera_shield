<template>
    <section class="souvera-section" data-testid="reputation-score">
        <header class="souvera-section__header">
            <div>
                <h2 class="souvera-heading-2">{{ t('Reputation score') }}</h2>
                <p class="souvera-section__sub">{{ t('Composite score from DMARC reports, mail tests, blacklists, infrastructure checks and open incidents. Components without data are excluded – nothing is estimated.') }}</p>
            </div>
            <div class="souvera-section__actions">
                <NcButton type="primary" :disabled="analyzing" data-testid="reputation-analyze-btn" @click="runAnalysis">
                    <template #icon><Radar :size="20" /></template>
                    {{ analyzing ? t('Analyzing…') : t('Run analysis now') }}
                </NcButton>
            </div>
        </header>

        <div v-if="loading" class="loading-row"><NcLoadingIcon :size="32" /></div>

        <template v-else-if="data">
            <div class="score-layout">
                <div class="score-ring" data-testid="reputation-score-value">
                    <svg viewBox="0 0 120 120" class="score-ring__svg" role="img" :aria-label="t('Reputation score')">
                        <circle cx="60" cy="60" r="52" class="score-ring__bg" />
                        <circle v-if="data.score !== null"
                                cx="60" cy="60" r="52"
                                class="score-ring__fg"
                                :class="scoreClass"
                                :stroke-dasharray="`${data.score * 3.267} 326.7`" />
                    </svg>
                    <div class="score-ring__label">
                        <strong v-if="data.score !== null" :class="scoreTextClass">{{ data.score }}</strong>
                        <strong v-else class="souvera-muted">—</strong>
                        <span>/ 100</span>
                    </div>
                </div>

                <div class="score-components">
                    <NcNoteCard v-if="data.state === 'insufficient_data'" type="warning" data-testid="reputation-score-nodata">
                        {{ t('Not enough real data for a score yet. Run a mail test and wait for the first DMARC reports – no value is invented.') }}
                    </NcNoteCard>
                    <div v-for="comp in data.components" :key="comp.id" class="score-comp" :data-testid="`reputation-component-${comp.id}`">
                        <div class="score-comp__head">
                            <span class="score-comp__label">{{ componentLabel(comp.id) }}</span>
                            <span class="score-comp__weight souvera-muted">{{ t('Weight') }} {{ comp.weight }}%</span>
                            <span v-if="comp.available" :class="['souvera-badge', badgeFor(comp.score)]">{{ comp.score }}</span>
                            <span v-else class="souvera-badge">{{ t('No data') }}</span>
                        </div>
                        <div class="score-comp__bar">
                            <div class="score-comp__fill" :class="comp.available ? barFor(comp.score) : ''" :style="{ width: (comp.available ? comp.score : 0) + '%' }" />
                        </div>
                        <p v-if="!comp.available" class="score-comp__hint souvera-muted">{{ noDataHint(comp) }}</p>
                    </div>
                </div>
            </div>

            <div v-if="history.length > 1" class="score-history" data-testid="reputation-score-history">
                <h3 class="score-history__title">{{ t('Score history') }}</h3>
                <div class="score-history__bars">
                    <div v-for="(h, i) in history" :key="i" class="score-history__bar"
                         :class="barFor(h.score)"
                         :style="{ height: Math.max(6, (h.score ?? 0)) + '%' }"
                         :title="fmtDate(h.ts) + ': ' + (h.score ?? '—')" />
                </div>
            </div>

            <div class="fbl-card" data-testid="reputation-fbl">
                <h3 class="score-history__title">{{ t('Complaints & feedback loops') }}</h3>
                <div class="fbl-card__rows">
                    <div class="fbl-card__row">
                        <span>{{ t('Forensic DMARC reports (RUF) received') }}</span>
                        <strong v-if="data.forensic && data.forensic.available">{{ data.forensic.reports }}</strong>
                        <span v-else class="souvera-badge">{{ t('No data') }}</span>
                    </div>
                    <div class="fbl-card__row">
                        <span>{{ t('RUF address published in DMARC record') }}</span>
                        <span :class="['souvera-badge', data.forensic && data.forensic.ruf_configured ? 'souvera-badge--ok' : '']">
                            {{ data.forensic && data.forensic.ruf_configured ? t('Yes') : t('No') }}
                        </span>
                    </div>
                </div>
                <p class="fbl-card__note souvera-muted">
                    {{ t('Complaint rates from Google and Microsoft are only available in their own portals (no public API). Register the domain at Google Postmaster Tools and Microsoft SNDS to see real complaint data there.') }}
                </p>
            </div>
        </template>
    </section>
</template>

<script>
import NcButton      from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard    from '@nextcloud/vue/components/NcNoteCard'
import Radar         from 'vue-material-design-icons/Radar.vue'

import { showSuccess, showError } from '@nextcloud/dialogs'
import api from '@/services/api'
import { t } from '@/services/i18n'

export default {
    name: 'ReputationScore',
    components: { NcButton, NcLoadingIcon, NcNoteCard, Radar },
    props: {
        days: { type: Number, default: 30 },
        reloadKey: { type: Number, default: 0 },
    },
    emits: ['analyzed'],
    setup() { return { t } },
    data() {
        return { loading: true, analyzing: false, data: null }
    },
    computed: {
        history() { return Array.isArray(this.data?.history) ? this.data.history : [] },
        scoreClass() {
            if (this.data?.score === null) return ''
            return this.data.score >= 80 ? 'is-ok' : (this.data.score >= 60 ? 'is-warn' : 'is-err')
        },
        scoreTextClass() { return this.scoreClass },
    },
    watch: {
        days() { this.load() },
        reloadKey() { this.load() },
    },
    async mounted() { await this.load() },
    methods: {
        componentLabel(id) {
            return {
                dmarc:          t('DMARC reports'),
                mail_test:      t('Latest mail test'),
                blacklist:      t('Blacklists'),
                infrastructure: t('Infrastructure checks'),
                incidents:      t('Open incidents'),
            }[id] || id
        },
        noDataHint(comp) {
            const reason = comp?.detail?.reason
            return {
                no_report_data:         t('No DMARC reports in the selected period yet.'),
                no_completed_mail_test: t('No completed mail test yet – start one below.'),
                no_blacklist_data:      t('Blacklist status could not be determined (missing outbound IP or the reputation service was unavailable).'),
                no_check_data:          t('Deliverability checks have not produced data yet.'),
            }[reason] || t('No data available for this component.')
        },
        badgeFor(v) {
            if (v === null || v === undefined) return ''
            if (v >= 80) return 'souvera-badge--ok'
            if (v >= 60) return 'souvera-badge--warn'
            return 'souvera-badge--err'
        },
        barFor(v) {
            if (v === null || v === undefined) return ''
            if (v >= 80) return 'is-ok'
            if (v >= 60) return 'is-warn'
            return 'is-err'
        },
        fmtDate(ts) { return ts ? new Date(ts * 1000).toLocaleDateString() : '' },
        async load() {
            this.loading = true
            try {
                this.data = await api.get('/api/reputation/overview', { days: this.days })
            } catch (e) {
                showError(api.errorMessage(e))
                this.data = null
            } finally {
                this.loading = false
            }
        },
        async runAnalysis() {
            this.analyzing = true
            try {
                const res = await api.post('/api/reputation/analyze', { days: this.days })
                showSuccess(t('Analysis completed – {open} open incidents.', { open: res?.incidents?.open ?? 0 }))
                await this.load()
                this.$emit('analyzed')
            } catch (e) {
                showError(api.errorMessage(e))
            } finally {
                this.analyzing = false
            }
        },
    },
}
</script>

<style scoped>
.loading-row { display: flex; justify-content: center; padding: 32px; }

.score-layout { display: flex; gap: 32px; align-items: flex-start; flex-wrap: wrap; }

.score-ring { position: relative; width: 160px; height: 160px; flex-shrink: 0; }
.score-ring__svg { width: 100%; height: 100%; transform: rotate(-90deg); }
.score-ring__bg { fill: none; stroke: var(--color-background-dark); stroke-width: 10; }
.score-ring__fg { fill: none; stroke-width: 10; stroke-linecap: round; transition: stroke-dasharray .6s ease; }
.score-ring__fg.is-ok   { stroke: hsl(142, 71%, 36%); }
.score-ring__fg.is-warn { stroke: hsl(32, 88%, 42%); }
.score-ring__fg.is-err  { stroke: hsl(0, 72%, 47%); }
.score-ring__label { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.score-ring__label strong { font-size: 2.4rem; line-height: 1; font-weight: 700; }
.score-ring__label strong.is-ok   { color: hsl(142, 71%, 36%); }
.score-ring__label strong.is-warn { color: hsl(32, 88%, 42%); }
.score-ring__label strong.is-err  { color: hsl(0, 72%, 47%); }
.score-ring__label span { font-size: .8rem; color: var(--color-text-maxcontrast); }

.score-components { flex: 1; min-width: 280px; display: flex; flex-direction: column; gap: 14px; }
.score-comp__head { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
.score-comp__label { font-weight: 600; font-size: .9rem; }
.score-comp__weight { font-size: .75rem; margin-left: auto; }
.score-comp__bar { height: 8px; border-radius: 4px; background: var(--color-background-dark); overflow: hidden; }
.score-comp__fill { height: 100%; border-radius: 4px; background: var(--color-text-maxcontrast); transition: width .5s ease; }
.score-comp__fill.is-ok   { background: hsl(142, 71%, 36%); }
.score-comp__fill.is-warn { background: hsl(32, 88%, 42%); }
.score-comp__fill.is-err  { background: hsl(0, 72%, 47%); }
.score-comp__hint { font-size: .78rem; margin: 4px 0 0; }

.score-history { margin-top: 28px; }
.score-history__title { font-size: .82rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--color-text-maxcontrast); margin-bottom: 8px; }
.score-history__bars { display: flex; align-items: flex-end; gap: 4px; height: 64px; }
.score-history__bar { width: 14px; border-radius: 3px 3px 0 0; background: var(--color-text-maxcontrast); min-height: 4px; }
.score-history__bar.is-ok   { background: hsl(142, 71%, 36%); }
.score-history__bar.is-warn { background: hsl(32, 88%, 42%); }
.score-history__bar.is-err  { background: hsl(0, 72%, 47%); }

.fbl-card { margin-top: 28px; border-top: 1px solid var(--color-border, var(--color-background-dark)); padding-top: 16px; }
.fbl-card__rows { display: flex; flex-direction: column; gap: 8px; }
.fbl-card__row { display: flex; align-items: center; gap: 12px; font-size: .9rem; }
.fbl-card__row > span:first-child { flex: 1; }
.fbl-card__note { font-size: .8rem; margin-top: 10px; }
</style>
