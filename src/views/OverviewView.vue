<template>
    <div>
        <header class="souvera-section__header">
            <div>
                <h1 class="souvera-heading-1">{{ t('Overview') }}</h1>
                <p class="souvera-section__sub">{{ t('Status of your inbound mail protection. Manage quarantine and personal rules from the menu on the left.') }}</p>
            </div>
        </header>

        <section class="souvera-section">
            <div class="souvera-stats" data-testid="overview-stats">
                <div v-for="stat in stats" :key="stat.key" class="souvera-stat" :data-testid="`overview-stat-${stat.key}`">
                    <span class="souvera-stat__label">{{ stat.label }}</span>
                    <strong class="souvera-stat__value">{{ stat.value.toLocaleString() }}</strong>
                </div>
            </div>
        </section>

        <section class="souvera-section" data-testid="overview-recent">
            <header class="souvera-section__header">
                <div>
                    <h2 class="souvera-heading-2">{{ t('Recent quarantine messages') }}</h2>
                    <p class="souvera-section__sub">{{ t('The most recent mails moved to quarantine within the last 24 hours.') }}</p>
                </div>
            </header>

            <div v-if="loading" class="loading-row">
                <NcLoadingIcon :size="32" />
            </div>

            <NcEmptyContent v-else-if="!recent.length"
                            :name="t('No recent quarantined messages.')"
                            data-testid="overview-empty">
                <template #icon><EmailCheck /></template>
            </NcEmptyContent>

            <table v-else class="recent-table" :aria-label="t('Recent quarantine messages')">
                <thead><tr>
                    <th class="col-time">{{ t('Received') }}</th>
                    <th class="col-from">{{ t('From') }}</th>
                    <th class="col-mailbox">{{ t('Mailbox') }}</th>
                    <th class="col-subject">{{ t('Subject') }}</th>
                    <th class="col-action">{{ t('Action') }}</th>
                </tr></thead>
                <tbody>
                    <tr v-for="row in recent" :key="row.id" data-testid="overview-recent-row">
                        <td class="col-time" :data-label="t('Received')">{{ formatTime(row.time) }}</td>
                        <td class="col-from" :data-label="t('From')"><span class="souvera-trunc" :title="row.from">{{ row.from }}</span></td>
                        <td class="col-mailbox" :data-label="t('Mailbox')">{{ row._pmail || '' }}</td>
                        <td class="col-subject" :data-label="t('Subject')"><span class="souvera-trunc" :title="row.subject">{{ row.subject }}</span></td>
                        <td class="col-action">
                            <NcButton type="tertiary" @click="goToQuarantine">
                                <template #icon><ArrowRight :size="18" /></template>
                            </NcButton>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>

<script>
import NcButton       from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon  from '@nextcloud/vue/components/NcLoadingIcon'

import EmailCheck  from 'vue-material-design-icons/EmailCheck.vue'
import ArrowRight  from 'vue-material-design-icons/ArrowRight.vue'

import { showError } from '@nextcloud/dialogs'

import api from '@/services/api'
import { t } from '@/services/i18n'
import { navigate } from '@/services/router'

export default {
    name: 'OverviewView',
    components: { NcButton, NcEmptyContent, NcLoadingIcon, EmailCheck, ArrowRight },
    setup() { return { t } },
    data() {
        return {
            loading: true,
            recent: [],
            counts: { quarantine: 0, whitelist: 0, blacklist: 0, file: 0, virus: 0 },
        }
    },
    computed: {
        stats() {
            const flags = window.OCA?.SouveraShield?.flags || {}
            const items = [
                { key: 'quarantine', label: t('Mails in quarantine'),   value: this.counts.quarantine },
                { key: 'whitelist',  label: t('Whitelist entries'),     value: this.counts.whitelist },
                { key: 'blacklist',  label: t('Blacklist entries'),     value: this.counts.blacklist },
            ]
            if (flags.allow_file_quarantine)  items.push({ key: 'file',  label: t('Attachments in quarantine'), value: this.counts.file })
            if (flags.allow_virus_quarantine) items.push({ key: 'virus', label: t('Viruses in quarantine'),     value: this.counts.virus })
            return items
        },
    },
    async mounted() {
        await this.load()
    },
    methods: {
        formatTime(ts) {
            if (!ts) return ''
            const num = Number(ts)
            return Number.isNaN(num) ? String(ts) : new Date(num * 1000).toLocaleString()
        },
        goToQuarantine() { navigate('quarantine') },
        async load() {
            this.loading = true
            const flags = window.OCA?.SouveraShield?.flags || {}
            try {
                const promises = [
                    api.get('/api/quarantine', { limit: 50 }),
                    api.get('/api/whitelist'),
                    api.get('/api/blacklist'),
                ]
                if (flags.allow_file_quarantine)  promises.push(api.get('/api/file_quarantine',  { all: 1 }))
                if (flags.allow_virus_quarantine) promises.push(api.get('/api/virus_quarantine', { all: 1 }))
                const results = await Promise.all(promises)
                const spam = Array.isArray(results[0]) ? results[0] : (results[0]?.data || [])
                this.counts.quarantine = spam.length
                this.counts.whitelist  = (Array.isArray(results[1]) ? results[1] : (results[1]?.data || [])).length
                this.counts.blacklist  = (Array.isArray(results[2]) ? results[2] : (results[2]?.data || [])).length
                let i = 3
                if (flags.allow_file_quarantine) {
                    const f = results[i++]
                    this.counts.file = (Array.isArray(f) ? f : (f?.data || [])).length
                }
                if (flags.allow_virus_quarantine) {
                    const v = results[i++]
                    this.counts.virus = (Array.isArray(v) ? v : (v?.data || [])).length
                }
                // Recent = last 24h from the spam quarantine list (limit 10)
                const cutoff = Date.now() / 1000 - 86400
                this.recent = spam
                    .filter(r => Number(r.time || 0) >= cutoff)
                    .sort((a, b) => Number(b.time || 0) - Number(a.time || 0))
                    .slice(0, 10)
            } catch (e) {
                showError(api.errorMessage(e))
            } finally {
                this.loading = false
            }
        },
    },
}
</script>

<style scoped>
.loading-row { display: flex; justify-content: center; padding: 32px; }
.recent-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
.recent-table th,
.recent-table td { padding: 10px 12px; border-bottom: 1px solid var(--color-border, var(--color-background-dark)); text-align: left; font-size: .9rem; }
.recent-table th { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: var(--color-text-maxcontrast); font-weight: 600; }
.col-time    { width: 11rem; white-space: nowrap; }
.col-from    { width: 12rem; max-width: 12rem; }
.col-mailbox { width: 14rem; max-width: 14rem; }
.col-subject { width: auto; }
.col-action  { width: 4rem; text-align: right; }
.souvera-trunc { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
