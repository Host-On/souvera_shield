<template>
    <section class="souvera-section" data-testid="reputation-charts">
        <header class="souvera-section__header">
            <div>
                <h2 class="souvera-heading-2">{{ t('Timeline') }}</h2>
                <p class="souvera-section__sub">{{ t('Daily volume and authentication pass rates over the selected period.') }}</p>
            </div>
        </header>

        <div v-if="loading" class="loading-row" data-testid="reputation-charts-loading">
            <NcLoadingIcon :size="32" />
        </div>

        <NcEmptyContent v-else-if="!buckets.length"
                        :name="t('Not enough report data yet to draw charts.')"
                        data-testid="reputation-charts-empty">
            <template #icon><ChartLine /></template>
        </NcEmptyContent>

        <div v-else class="charts-grid">
            <div class="souvera-card chart-card">
                <div class="chart-card__title">{{ t('Messages per day') }}</div>
                <div class="chart-card__body">
                    <Bar :data="messagesData" :options="messagesOptions" data-testid="reputation-chart-messages" />
                </div>
            </div>

            <div class="souvera-card chart-card">
                <div class="chart-card__title">{{ t('Pass rate over time') }}</div>
                <div class="chart-card__body">
                    <Line :data="passRateData" :options="passRateOptions" data-testid="reputation-chart-passrate" />
                </div>
            </div>
        </div>
    </section>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon  from '@nextcloud/vue/components/NcLoadingIcon'
import ChartLine      from 'vue-material-design-icons/ChartLine.vue'

import { Bar, Line } from 'vue-chartjs'
import {
    Chart as ChartJS,
    BarElement,
    LineElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js'

import { showError } from '@nextcloud/dialogs'

import api from '@/services/api'
import { t } from '@/services/i18n'

// Register only the chart primitives we actually use – keeps the bundle
// small and avoids side-effects on other Chart.js usages.
ChartJS.register(BarElement, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend, Filler)

/**
 * ReputationCharts – client-side aggregation of the last N days of DMARC
 * aggregate reports into daily buckets, rendered as two Chart.js canvases:
 *
 *   1. Bar chart  – total messages per day
 *   2. Line chart – DKIM & SPF pass rates per day (0–100 %)
 *
 * The backend already exposes the raw reports through
 *   GET /api/dmarc/domain/reports?page=1&limit=1000
 * which we page through until we've collected the days-window we need.
 * We deliberately avoid a new backend endpoint – the frontend can slice
 * the existing data with zero extra server work.
 */
export default {
    name: 'ReputationCharts',
    components: { NcEmptyContent, NcLoadingIcon, ChartLine, Bar, Line },
    props: { days: { type: Number, default: 30 } },
    setup() { return { t } },
    data() {
        return {
            loading: true,
            buckets: [],   // [{ dateISO, label, total, dkimOk, spfOk }, …] sorted asc
        }
    },
    computed: {
        // ---------- Theme integration ----------
        themeVar() {
            const cs = getComputedStyle(document.documentElement)
            return (name, fallback) => (cs.getPropertyValue(name).trim() || fallback)
        },
        colors() {
            const themeVar = this.themeVar
            const primaryRgb = themeVar('--color-primary-element-rgb', '0, 130, 201')
            return {
                primary:      `rgba(${primaryRgb}, 0.85)`,
                primaryFill:  `rgba(${primaryRgb}, 0.15)`,
                success:      themeVar('--color-success', '#2ecc71'),
                warning:      themeVar('--color-warning', '#f0a53c'),
                text:         themeVar('--color-main-text', '#222'),
                border:       themeVar('--color-border', '#e5e5e5'),
                muted:        themeVar('--color-text-maxcontrast', '#767676'),
            }
        },

        // ---------- Datasets ----------
        messagesData() {
            return {
                labels:   this.buckets.map(b => b.label),
                datasets: [{
                    label: this.t('Messages'),
                    data: this.buckets.map(b => b.total),
                    backgroundColor: this.colors.primary,
                    borderRadius: 4,
                    maxBarThickness: 24,
                }],
            }
        },
        passRateData() {
            return {
                labels: this.buckets.map(b => b.label),
                datasets: [
                    {
                        label: this.t('DKIM pass'),
                        data:  this.buckets.map(b => pct(b.dkimOk, b.total)),
                        borderColor: this.colors.success,
                        backgroundColor: this.colors.success + '22',
                        tension: 0.3,
                        fill: false,
                        pointRadius: 3,
                        borderWidth: 2,
                    },
                    {
                        label: this.t('SPF pass'),
                        data:  this.buckets.map(b => pct(b.spfOk, b.total)),
                        borderColor: this.colors.warning,
                        backgroundColor: this.colors.warning + '22',
                        tension: 0.3,
                        fill: false,
                        pointRadius: 3,
                        borderWidth: 2,
                    },
                ],
            }
        },

        // ---------- Options ----------
        commonOptions() {
            const c = this.colors
            return {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 400 },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { color: c.text, boxWidth: 12, boxHeight: 12 },
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0,0,0,0.85)',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        borderColor: c.border,
                        borderWidth: 1,
                        padding: 10,
                    },
                },
                scales: {
                    x: {
                        ticks: { color: c.muted, maxRotation: 0, autoSkipPadding: 12 },
                        grid: { color: c.border + '55' },
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: c.muted },
                        grid: { color: c.border + '55' },
                    },
                },
            }
        },
        messagesOptions() {
            const base = this.commonOptions
            return {
                ...base,
                plugins: {
                    ...base.plugins,
                    legend: { display: false },
                },
            }
        },
        passRateOptions() {
            const base = this.commonOptions
            return {
                ...base,
                scales: {
                    ...base.scales,
                    y: {
                        ...base.scales.y,
                        min: 0,
                        max: 100,
                        ticks: { ...base.scales.y.ticks, callback: v => v + '%' },
                    },
                },
            }
        },
    },
    watch: {
        days: {
            immediate: true,
            handler() { this.load() },
        },
    },
    methods: {
        async load() {
            this.loading = true
            try {
                const reports = await this.fetchAllReports()
                this.buckets = this.bucketByDay(reports)
            } catch (e) {
                showError(api.errorMessage(e))
                this.buckets = []
            } finally {
                this.loading = false
            }
        },

        /**
         * Fetch enough reports to cover the requested `days` window.
         * Reports API is paginated (10/page in our UI, but we ask for
         * up to 200 here). We stop early once we've walked past the
         * cut-off date.
         */
        async fetchAllReports() {
            const cutoff = Date.now() - this.days * 86400 * 1000
            const out = []
            let page = 1
            const limit = 100
            for (let safety = 0; safety < 20; safety++) {
                let data
                try {
                    data = await api.get('/api/dmarc/domain/reports', { page, limit })
                } catch (e) {
                    // Any backend error → surface it to the caller.
                    throw e
                }
                const rows = Array.isArray(data?.reports) ? data.reports : []
                if (!rows.length) break
                out.push(...rows)
                const oldest = rows.reduce((min, r) => {
                    const t = new Date(r.dateRangeBegin || 0).getTime()
                    return t && t < min ? t : min
                }, Number.POSITIVE_INFINITY)
                if (Number.isFinite(oldest) && oldest < cutoff) break
                const total = Number(data?.total ?? data?.totalCount ?? out.length)
                if (out.length >= total) break
                page += 1
            }
            return out
        },

        /**
         * Turn the flat report list into per-day buckets, filtered to
         * the current `days` window and sorted ascending. Reports may
         * span multiple days – for aggregation we anchor on
         * dateRangeBegin (the far-more-common one-day report shape).
         */
        bucketByDay(reports) {
            const cutoff = Date.now() - this.days * 86400 * 1000
            const map = new Map()
            for (const r of reports) {
                const beginMs = new Date(r.dateRangeBegin || 0).getTime()
                if (!beginMs || beginMs < cutoff) continue
                const key = new Date(beginMs).toISOString().slice(0, 10)
                if (!map.has(key)) {
                    map.set(key, { dateISO: key, total: 0, dkimOk: 0, spfOk: 0 })
                }
                const b = map.get(key)
                b.total  += Number(r.totalMessages || 0)
                b.dkimOk += Number(r.passedDkim || 0)
                b.spfOk  += Number(r.passedSpf  || 0)
            }
            const sorted = Array.from(map.values()).sort((a, b) => a.dateISO.localeCompare(b.dateISO))
            const shortFmt = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' })
            for (const b of sorted) {
                b.label = shortFmt.format(new Date(b.dateISO))
            }
            return sorted
        },
    },
}

function pct(passed, total) {
    const t = Number(total || 0)
    if (t <= 0) return null
    return Math.round((Number(passed || 0) / t) * 100)
}
</script>

<style scoped>
.loading-row { display: flex; justify-content: center; padding: 32px; }
.charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
    gap: 16px;
}
.chart-card { padding: 16px 20px 20px; }
.chart-card__title {
    font-size: .82rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--color-text-maxcontrast);
    margin-bottom: 12px;
}
.chart-card__body {
    height: 280px;
    position: relative;
}
</style>
