<template>
    <section class="souvera-section" data-testid="suspicious-logins">
        <header class="souvera-section__header">
            <div>
                <h1 class="souvera-heading-1">{{ t('Suspicious logins') }}</h1>
                <p class="souvera-section__sub">{{ t('Login events flagged by the behavioural analysis engine. Review and resolve each one.') }}</p>
            </div>
            <div class="souvera-section__actions">
                <div v-if="isAdmin" class="search-field">
                    <Magnify :size="16" class="search-icon" />
                    <input v-model="search"
                           type="search"
                           :placeholder="t('Search user…')"
                           class="souvera-input"
                           data-testid="suspicious-logins-search"
                           @input="onSearch" />
                </div>
                <div class="segmented" role="tablist" data-testid="suspicious-logins-filter-status">
                    <button v-for="opt in statusFilters" :key="opt.id"
                            type="button"
                            class="segmented__btn"
                            :class="{ 'is-active': statusFilter === opt.id }"
                            :data-testid="`suspicious-logins-filter-${opt.id}`"
                            @click="onFilterChange('status', opt.id)">
                        {{ opt.label }}
                    </button>
                </div>
                <div class="segmented" role="tablist" data-testid="suspicious-logins-filter-severity">
                    <button v-for="opt in severityFilters" :key="opt.id"
                            type="button"
                            class="segmented__btn"
                            :class="{ 'is-active': severityFilter === opt.id }"
                            :data-testid="`suspicious-logins-sev-${opt.id}`"
                            @click="onFilterChange('severity', opt.id)">
                        {{ opt.label }}
                    </button>
                </div>
            </div>
        </header>

        <div v-if="loading" class="loading-row"><NcLoadingIcon :size="32" /></div>

        <NcEmptyContent v-else-if="!events.length"
                        :name="t('No suspicious logins detected.')"
                        :description="t('The behavioural analysis engine has not flagged any logins yet.')"
                        data-testid="suspicious-logins-empty">
            <template #icon><ShieldCheck :size="40" /></template>
        </NcEmptyContent>

        <div v-else class="logins-table-wrap">
            <div class="logins-table-scroll">
                <table class="logins-table" :aria-label="t('Suspicious logins')">
                    <thead><tr>
                        <th class="col-sev"></th>
                        <th>{{ t('User') }}</th>
                        <th>{{ t('IP') }}</th>
                        <th class="col-country">{{ t('Country') }}</th>
                        <th class="col-isp">{{ t('ISP') }}</th>
                        <th class="col-score">{{ t('Score') }}</th>
                        <th class="col-rules">{{ t('Rules') }}</th>
                        <th class="col-time">{{ t('Time') }}</th>
                        <th class="col-status">{{ t('Status') }}</th>
                        <th class="col-action"><span class="sr-only">{{ t('Details') }}</span></th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="ev in events" :key="ev.id"
                            data-testid="suspicious-login-row"
                            :class="{ 'is-resolved': ev.resolved }"
                            @click="openDetails(ev)">
                            <td class="col-sev">{{ sevIcon(ev.severity) }}</td>
                            <td class="col-user">{{ ev.user_id }}</td>
                            <td><code>{{ ev.ip || '—' }}</code></td>
                            <td class="col-country souvera-muted">{{ ev.geo_country || '—' }}</td>
                            <td class="col-isp souvera-muted">{{ truncate(ev.isp_name, 24) || '—' }}</td>
                            <td class="col-score">
                                <span :class="['souvera-badge', scoreBadge(ev.confidence)]">{{ ev.confidence }}</span>
                            </td>
                            <td class="col-rules souvera-muted">{{ firedRuleCount(ev.risk_flags) }}</td>
                            <td class="col-time souvera-muted">{{ fmtTime(ev.created_at) }}</td>
                            <td class="col-status">
                                <span :class="['souvera-badge', ev.resolved ? 'souvera-badge--ok' : 'souvera-badge--warn']">
                                    {{ ev.resolved ? t('Closed') : t('Open') }}
                                </span>
                            </td>
                            <td class="col-action">
                                <NcButton type="tertiary" :aria-label="t('Details')" :title="t('Details')"
                                          data-testid="suspicious-login-details-btn"
                                          @click.stop="openDetails(ev)">
                                    <template #icon><InformationOutline :size="20" /></template>
                                </NcButton>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pager
                :page="currentPage"
                :page-count="totalPages"
                :total="total"
                :per-page="perPage"
                testid-prefix="suspicious-logins-pager"
                @update:page="onPageChange" />
        </div>

        <NcDialog v-if="details"
                  :open="true"
                  :name="t('Login details')"
                  size="large"
                  data-testid="suspicious-login-dialog"
                  @update:open="details = null">
            <div class="login-dialog">
                <div class="login-dialog__badges">
                    <span class="login-dialog__sev">{{ sevIcon(details.severity) }}
                        {{ sevLabel(details.severity) }}</span>
                    <span :class="['souvera-badge', scoreBadge(details.confidence)]">
                        {{ t('Score: {score}', { score: details.confidence }) }}
                    </span>
                    <span :class="['souvera-badge', details.resolved ? 'souvera-badge--ok' : 'souvera-badge--warn']">
                        {{ details.resolved ? t('Resolved') : t('Open') }}
                    </span>
                </div>

                <p class="login-dialog__explain">
                    {{ t('Das System hat diese Anmeldung als auffällig eingestuft. Jede Regel prüft ein bestimmtes Merkmal (z. B. neues Land, unbekannte IP, Hosting-Anbieter) und vergibt Punkte. Je mehr Punkte, desto verdächtiger.') }}
                </p>

                <h4>{{ t('Decision') }}</h4>
                <p class="decision-text" data-testid="suspicious-login-decision">{{ details.decision || '—' }}</p>

                <h4>{{ t('Network details') }}</h4>
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">{{ t('IP') }}</span>
                        <code>{{ details.ip || '—' }}</code>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">{{ t('Country') }}</span>
                        <span>{{ details.geo_country || '—' }}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">{{ t('City') }}</span>
                        <span>{{ details.geo_city || '—' }}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">{{ t('ISP') }}</span>
                        <span>{{ details.isp_name || '—' }}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">{{ t('ASN') }}</span>
                        <span>{{ (details.trace && details.trace.asn) || '—' }}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">{{ t('User agent') }}</span>
                        <span class="truncate-text">{{ (details.trace && details.trace.user_agent) || '—' }}</span>
                    </div>
                </div>

                <h4>{{ t('Risk flags') }}</h4>
                <div v-if="riskFlags.length" class="risk-flags-list">
                    <span v-for="rf in riskFlags" :key="rf.key"
                          :class="['souvera-badge', rf.active ? 'souvera-badge--err' : 'souvera-badge--dim']">
                        {{ rf.label }}
                    </span>
                </div>
                <p v-else class="souvera-muted">{{ t('No risk flags recorded.') }}</p>

                <h4>{{ t('Rules that fired') }}</h4>
                <div v-if="firedRules.length" class="rules-table-wrapper">
                    <table class="rules-table">
                        <thead><tr>
                            <th>{{ t('Rule') }}</th>
                            <th class="col-rscore">{{ t('Points') }}</th>
                            <th class="col-rdesc">{{ t('Erklärung') }}</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="r in firedRules" :key="r.key">
                                <td>{{ r.label }}</td>
                                <td class="col-rscore">{{ r.points }}</td>
                                <td class="col-rdesc souvera-muted">{{ r.description }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="souvera-muted">{{ t('No rules triggered.') }}</p>

                <div v-if="details.resolved_by" class="resolution-info">
                    <h4>{{ t('Resolution') }}</h4>
                    <p>
                        {{ t('Resolved by {user} on {date}', {
                            user: details.resolved_by,
                            date: fmtDateTime(details.resolved_at) }) }}
                    </p>
                </div>
            </div>
            <template #actions>
                <template v-if="!details.resolved">
                    <NcButton v-if="isAdmin" type="primary" :disabled="resolving"
                              data-testid="suspicious-login-confirm"
                              @click="resolve(details, 'confirmed_threat')">
                        {{ resolving === 'confirmed_threat' ? t('Saving…') : t('Bestätigen') }}
                    </NcButton>
                    <NcButton type="error" :disabled="!!resolving"
                              data-testid="suspicious-login-false-positive"
                              @click="resolve(details, 'false_positive')">
                        {{ resolving === 'false_positive' ? t('Saving…') : t('Falsch positiv') }}
                    </NcButton>
                    <NcButton :disabled="!!resolving"
                              data-testid="suspicious-login-travel"
                              @click="resolve(details, 'user_travel')">
                        {{ resolving === 'user_travel' ? t('Saving…') : t('Auf Reisen') }}
                    </NcButton>
                    <NcButton :disabled="!!resolving"
                              data-testid="suspicious-login-known-location"
                              @click="resolve(details, 'known_location')">
                        {{ resolving === 'known_location' ? t('Saving…') : t('Bekannter Ort') }}
                    </NcButton>
                </template>
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
import Magnify            from 'vue-material-design-icons/Magnify.vue'

import { showSuccess, showError } from '@nextcloud/dialogs'

import Pager from '@/components/Pager.vue'
import api from '@/services/api'
import { t } from '@/services/i18n'

const SEVERITY_ICONS = {
    critical: '\uD83D\uDC80',
    high:     '\uD83D\uDD34',
    medium:   '\uD83D\uDFE0',
    low:      '\uD83D\uDFE1',
}

const RULE_LABELS = {
    new_country:           { label: t('Neues Land'),    desc: t('Login aus einem Land, aus dem sich dieser Benutzer noch nie angemeldet hat.') },
    new_isp:               { label: t('Neuer Anbieter'), desc: t('Der Internet-Anbieter (ISP) wurde bei diesem Benutzer noch nie gesehen.') },
    new_subnet:            { label: t('Neues Netzwerk'), desc: t('Die IP-Adresse gehört zu einem neuen Teilnetz. Normal bei Mobilempfang oder Reisen.') },
    new_device:            { label: t('Neues Gerät'),    desc: t('Browser oder App wurden bisher nicht für diesen Benutzer registriert.') },
    off_hours:             { label: t('Unübliche Uhrzeit'), desc: t('Login außerhalb der üblichen Zeiten dieses Benutzers (z. B. nachts).') },
    hosting:               { label: t('Rechenzentrums-IP'), desc: t('Die IP-Adresse gehört zu einem Hosting- oder Cloud-Anbieter — kein normaler Privatanschluss.') },
    vpn_proxy:             { label: t('VPN / Proxy'),    desc: t('Die IP-Adresse ist als VPN- oder Proxy-Dienst bekannt.') },
    tor:                   { label: t('Tor-Netzwerk'),   desc: t('Die IP-Adresse ist ein Tor-Exit-Knoten — wird häufig für anonyme Zugriffe genutzt.') },
    blocklisted:           { label: t('Auf Blacklist'),  desc: t('Die IP steht auf einer oder mehreren globalen Sperrlisten (Spam/Abuse).') },
    login_spike:           { label: t('Login-Häufung'),  desc: t('Ungewöhnlich viele Logins in kurzer Zeit — möglicher Brute-Force-Versuch.') },
    failed_then_success:   { label: t('Fehlversuche'),   desc: t('Mehrere fehlgeschlagene Logins von dieser IP, gefolgt von einem erfolgreichen Login.') },
    feedback_adjustment:   { label: t('Feedback-Anpassung'), desc: t('Punktzahl wurde durch früheres Feedback angepasst.') },
}

export default {
    name: 'SuspiciousLoginView',
    components: { NcButton, NcDialog, NcEmptyContent, NcLoadingIcon, ShieldCheck, InformationOutline, Magnify, Pager },
    setup() { return { t } },
    data() {
        return {
            loading: true,
            events: [],
            total: 0,
            isAdmin: false,
            statusFilter: 'open',
            severityFilter: 'all',
            search: '',
            searchTimer: null,
            currentPage: 1,
            perPage: 20,
            details: null,
            resolving: false,
        }
    },
    computed: {
        totalPages() {
            return Math.ceil(this.total / this.perPage) || 1
        },
        statusFilters() {
            return [
                { id: 'open',     label: t('Open') },
                { id: 'resolved', label: t('Closed') },
                { id: 'all',      label: t('All') },
            ]
        },
        severityFilters() {
            return [
                { id: 'all',      label: t('All') },
                { id: 'critical', label: t('Critical') },
                { id: 'high',     label: t('High') },
                { id: 'medium',   label: t('Medium') },
                { id: 'low',      label: t('Low') },
            ]
        },
        riskFlags() {
            const flags = this.details?.risk_flags
            if (!flags || typeof flags !== 'object') return []
            return Object.entries(flags).map(([key, points]) => {
                const info = RULE_LABELS[key] || { label: key }
                return {
                    key,
                    label: info.label,
                    active: parseInt(points) > 0,
                    points: parseInt(points) || 0,
                }
            })
        },
        firedRules() {
            const flags = this.details?.risk_flags
            if (!flags || typeof flags !== 'object') return []
            return Object.entries(flags)
                .filter(([, points]) => parseInt(points) > 0)
                .map(([key, points]) => {
                    const info = RULE_LABELS[key] || { label: key, desc: '' }
                    return {
                        key,
                        label: info.label,
                        description: info.desc,
                        points: parseInt(points) || 0,
                    }
                })
        },
    },
    async mounted() { await this.load() },
    methods: {
        sevIcon(s) { return SEVERITY_ICONS[s] || '' },
        sevLabel(s) {
            return { critical: t('Critical'), high: t('High'), medium: t('Medium'), low: t('Low') }[s] || s
        },
        scoreBadge(score) {
            if (score >= 80) return 'souvera-badge--err'
            if (score >= 60) return 'souvera-badge--warn'
            if (score >= 40) return ''
            return 'souvera-badge--ok'
        },
        firedRuleCount(flags) {
            if (!flags || typeof flags !== 'object') return '0'
            return Object.values(flags).filter(p => parseInt(p) > 0).length
        },
        fmtTime(ts) { return ts ? new Date(ts * 1000).toLocaleString() : '' },
        fmtDateTime(ts) { return ts ? new Date(ts * 1000).toLocaleString() : '' },
        truncate(s, n) { return s && s.length > n ? s.slice(0, n) + '…' : s },
        onSearch() {
            clearTimeout(this.searchTimer)
            this.searchTimer = setTimeout(() => { this.currentPage = 1; this.load() }, 350)
        },
        onFilterChange(type, value) {
            if (type === 'status') this.statusFilter = value
            else this.severityFilter = value
            this.currentPage = 1
            this.load()
        },
        onPageChange(page) {
            this.currentPage = page
            this.load()
        },
        async openDetails(ev) {
            try {
                const data = await api.get(`/api/suspicious-logins/${ev.id}`)
                this.details = data
            } catch (e) {
                showError(api.errorMessage(e))
            }
        },
        async load() {
            this.loading = true
            try {
                const params = {
                    limit: this.perPage,
                    offset: (this.currentPage - 1) * this.perPage,
                }
                if (this.statusFilter === 'open') params.resolved = 0
                else if (this.statusFilter === 'resolved') params.resolved = 1
                if (this.severityFilter !== 'all') params.severity = this.severityFilter
                if (this.search.trim()) params.userId = this.search.trim()

                const data = await api.get('/api/suspicious-logins', params)
                this.events = data?.events ?? (Array.isArray(data) ? data : [])
                this.total = data?.total ?? this.events.length
                this.isAdmin = data?.user?.is_admin ?? false

                // Clamp page if it runs past the last page
                const maxPage = Math.max(1, Math.ceil(this.total / this.perPage))
                if (this.currentPage > maxPage) {
                    this.currentPage = maxPage
                    await this.load()
                    return
                }
            } catch (e) {
                showError(api.errorMessage(e))
                this.events = []
                this.total = 0
            } finally {
                this.loading = false
            }
        },
        async resolve(ev, feedback) {
            this.resolving = feedback
            try {
                const updated = await api.post(`/api/suspicious-logins/${ev.id}/resolve`, { feedback })
                showSuccess(t('Login event resolved.'))
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

.search-field {
    position: relative;
    display: flex;
    align-items: center;
}
.search-icon {
    position: absolute;
    left: 10px;
    color: var(--color-text-maxcontrast);
    pointer-events: none;
}
.search-field .souvera-input {
    padding-left: 32px;
    width: 180px;
}
.souvera-input {
    height: var(--sc-control-height, 44px);
    padding: var(--sc-control-padding-y, 10px) var(--sc-control-padding-x, 16px);
    border: var(--sc-control-border-width, 1.5px) solid var(--color-border, var(--color-background-dark));
    border-radius: var(--sc-control-radius, 12px);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: .9rem;
    box-sizing: border-box;
}
.souvera-input:focus {
    outline: none;
    border-color: var(--color-primary-element);
    box-shadow: var(--sc-focus-ring);
}

.segmented { display: inline-flex; border: 1px solid var(--color-border, var(--color-background-dark)); border-radius: var(--border-radius-large, 12px); overflow: hidden; background: var(--color-main-background); }
.segmented__btn {
    background: transparent; border: 0; padding: 8px 16px; font-size: .85rem; font-weight: 600;
    color: var(--color-text-maxcontrast); cursor: pointer;
    border-right: 1px solid var(--color-border, var(--color-background-dark));
}
.segmented__btn:last-child { border-right: 0; }
.segmented__btn:hover:not(.is-active) { background: var(--color-background-hover); color: var(--color-main-text); }
.segmented__btn.is-active { background: var(--color-primary-element); color: var(--color-primary-element-text); }

.logins-table-wrap {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large, 12px);
    overflow: hidden;
    margin-bottom: 12px;
}

.logins-table-scroll {
    overflow-x: auto;
}

.logins-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
    color: var(--color-main-text);
    min-width: 700px;
}

.logins-table thead th {
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

.logins-table td {
    padding: 10px 16px;
    border-bottom: 1px solid var(--color-border);
    vertical-align: middle;
}

.logins-table tbody tr {
    cursor: pointer;
    transition: background-color .15s ease;
}
.logins-table tbody tr:hover {
    background: var(--color-background-hover);
}
.logins-table tbody tr:last-child td {
    border-bottom: none;
}
.logins-table tbody tr.is-resolved {
    opacity: .55;
}

.logins-table code {
    background: var(--color-background-dark);
    padding: 1px 6px;
    border-radius: 4px;
    font-size: .85rem;
}

.col-sev    { width: 2.5rem; text-align: center; }
.col-country { width: 5rem; }
.col-isp     { width: 10rem; }
.col-score   { width: 4.5rem; text-align: center; }
.col-rules   { width: 4rem; text-align: center; }
.col-time    { width: 10rem; white-space: nowrap; }
.col-status  { width: 6rem; }
.col-action  { width: 3rem; text-align: right; }
.col-user    { min-width: 8rem; }

.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }

.login-dialog h4 { margin: 16px 0 4px; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: var(--color-text-maxcontrast); }
.login-dialog h4:first-child { margin-top: 0; }
.login-dialog p { margin: 0; font-size: .9rem; }
.login-dialog code { background: var(--color-background-dark); padding: 1px 6px; border-radius: 4px; }
.login-dialog__badges { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.login-dialog__sev { font-size: .95rem; font-weight: 600; }
.decision-text { color: var(--color-main-text); font-weight: 500; }

.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 16px; }
.detail-item { display: flex; flex-direction: column; }
.detail-label { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: var(--color-text-maxcontrast); }
.truncate-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 240px; }

.risk-flags-list { display: flex; flex-wrap: wrap; gap: 6px; }
.souvera-badge--dim { opacity: .35; }

.rules-table-wrapper { overflow-x: auto; }
.rules-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
.rules-table th, .rules-table td { padding: 5px 10px; border-bottom: 1px solid var(--color-border, var(--color-background-dark)); text-align: left; }
.rules-table th { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: var(--color-text-maxcontrast); }
.rules-table .col-rscore { width: 4rem; text-align: right; }
.rules-table .col-rdesc { font-size: .78rem; color: var(--color-text-maxcontrast); }

.resolution-info { margin-top: 8px; }
.login-dialog__explain { font-size: .85rem; color: var(--color-text-maxcontrast); margin-bottom: 12px; line-height: 1.5; }

@media (max-width: 768px) {
    .logins-table { font-size: .82rem; }
    .logins-table th,
    .logins-table td { padding: 8px 10px; }
}
</style>
