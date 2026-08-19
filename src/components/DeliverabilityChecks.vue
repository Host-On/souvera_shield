<template>
    <section class="souvera-section" data-testid="reputation-checks">
        <header class="souvera-section__header">
            <div>
                <h2 class="souvera-heading-2">{{ t('Deliverability checks') }}</h2>
                <p class="souvera-section__sub">{{ t('SPF/DKIM alignment, PTR, HELO, TLS, MTA-STS, TLS-RPT, BIMI, One-Click-Unsubscribe and blacklists – every warning explains the problem and how to fix it.') }}</p>
            </div>
            <div class="souvera-section__actions">
                <NcButton type="secondary" :disabled="loading" data-testid="reputation-checks-refresh" @click="load(true)">
                    <template #icon><Refresh :size="20" /></template>
                    {{ t('Re-run checks') }}
                </NcButton>
            </div>
        </header>

        <div v-if="loading" class="loading-row"><NcLoadingIcon :size="32" /></div>

        <template v-else-if="data">
            <p v-if="data.outbound_ip" class="checks-meta souvera-muted" data-testid="reputation-checks-ip">
                {{ t('Outbound IP:') }} <code>{{ data.outbound_ip }}</code>
                <span v-if="data.ip_source === 'mail_test'">({{ t('observed in the last mail test') }})</span>
                <span v-else-if="data.ip_source === 'stalwart'">({{ t('resolved from the Stalwart host') }})</span>
                <span v-else-if="data.ip_source === 'mx'">({{ t('resolved from the domain MX record') }})</span>
                · {{ t('Checked:') }} {{ fmtDateTime(data.generated_at) }}
            </p>

            <ul class="check-list">
                <li v-for="check in data.checks" :key="check.id" class="check-item" :data-testid="`reputation-check-${check.id}`">
                    <button type="button" class="check-item__row" :data-testid="`reputation-check-toggle-${check.id}`" @click="toggle(check.id)">
                        <span :class="['souvera-badge', statusClass(check.status)]">{{ statusLabel(check.status) }}</span>
                        <span class="check-item__title">{{ info(check.id).title }}</span>
                        <span class="check-item__summary souvera-muted">{{ summaryOf(check) }}</span>
                        <ChevronDown :size="18" class="check-item__chevron" :class="{ 'is-open': open === check.id }" />
                    </button>
                    <div v-if="open === check.id" class="check-item__details" :data-testid="`reputation-check-details-${check.id}`">
                        <p class="check-item__what">{{ info(check.id).what }}</p>
                        <template v-if="check.status === 'warn' || check.status === 'fail'">
                            <p><strong>{{ t('Problem:') }}</strong> {{ problemOf(check) }}</p>
                            <p><strong>{{ t('How to fix:') }}</strong> {{ info(check.id).fix }}</p>
                        </template>
                        <p v-else-if="check.status === 'nodata'"><strong>{{ t('No data:') }}</strong> {{ nodataOf(check) }}</p>
                        <p v-else-if="check.status === 'info'"><strong>{{ t('Note:') }}</strong> {{ info(check.id).optional }}</p>
                        <dl v-if="observedRows(check).length" class="check-item__observed">
                            <template v-for="row in observedRows(check)" :key="row.k">
                                <dt>{{ row.k }}</dt>
                                <dd><code>{{ row.v }}</code></dd>
                            </template>
                        </dl>
                    </div>
                </li>
            </ul>
        </template>
    </section>
</template>

<script>
import NcButton      from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Refresh       from 'vue-material-design-icons/Refresh.vue'
import ChevronDown   from 'vue-material-design-icons/ChevronDown.vue'

import { showError } from '@nextcloud/dialogs'
import api from '@/services/api'
import { t } from '@/services/i18n'

export default {
    name: 'DeliverabilityChecks',
    components: { NcButton, NcLoadingIcon, Refresh, ChevronDown },
    props: { reloadKey: { type: Number, default: 0 } },
    setup() { return { t } },
    data() {
        return { loading: true, data: null, open: null }
    },
    watch: {
        reloadKey() { this.load(false) },
    },
    async mounted() { await this.load(false) },
    methods: {
        toggle(id) { this.open = this.open === id ? null : id },
        fmtDateTime(ts) { return ts ? new Date(ts * 1000).toLocaleString() : '' },
        statusClass(s) {
            return { ok: 'souvera-badge--ok', warn: 'souvera-badge--warn', fail: 'souvera-badge--err' }[s] || ''
        },
        statusLabel(s) {
            return { ok: t('OK'), warn: t('Warning'), fail: t('Failed'), info: t('Optional'), nodata: t('No data') }[s] || s
        },
        info(id) {
            const map = {
                spf_record: {
                    title: t('SPF record'),
                    what: t('SPF lists the servers that are allowed to send mail for your domain. Receivers reject or distrust mail from unlisted servers.'),
                    fix: t('Publish a TXT record "v=spf1 … -all" on the domain that includes every legitimate sending server, and keep it under 10 DNS lookups.'),
                    optional: '',
                },
                dmarc_policy: {
                    title: t('DMARC policy'),
                    what: t('DMARC tells receivers what to do with unauthenticated mail and where to send reports. Without an enforcing policy anyone can spoof your domain.'),
                    fix: t('Publish _dmarc.<domain> with p=quarantine or p=reject, a rua= report address and pct=100.'),
                    optional: '',
                },
                mta_sts: {
                    title: t('MTA-STS'),
                    what: t('MTA-STS lets sending servers verify that mail to your domain must be delivered over verified TLS – it prevents downgrade attacks.'),
                    fix: t('Publish the _mta-sts TXT record and serve the policy file at https://mta-sts.<domain>/.well-known/mta-sts.txt with mode: enforce.'),
                    optional: t('MTA-STS is not configured. It is optional but recommended: it protects inbound mail against TLS downgrade attacks.'),
                },
                tls_rpt: {
                    title: t('TLS-RPT'),
                    what: t('TLS-RPT delivers daily reports about failed TLS connections to your mail server, so encryption problems become visible.'),
                    fix: t('Publish the TXT record _smtp._tls.<domain> with "v=TLSRPTv1; rua=mailto:…".'),
                    optional: t('TLS-RPT is not configured. Optional, but it is the only way to learn when senders fail to reach you over TLS.'),
                },
                bimi: {
                    title: t('BIMI'),
                    what: t('BIMI displays your logo next to authenticated mail in Gmail, Yahoo and Apple Mail – it requires an enforcing DMARC policy.'),
                    fix: t('Set DMARC to p=quarantine or p=reject first, then publish default._bimi.<domain> with an SVG logo (and a VMC certificate for Gmail).'),
                    optional: t('BIMI is not configured. Purely optional branding – requires DMARC enforcement first.'),
                },
                ptr: {
                    title: t('PTR / Reverse DNS'),
                    what: t('The outbound IP must resolve to a hostname that resolves back to the same IP (FCrDNS). Many receivers reject mail from IPs without PTR.'),
                    fix: t('Ask the IP owner (hoster) to set a PTR record matching the mail server hostname, and make sure that hostname has a matching A record.'),
                    optional: '',
                },
                helo_tls: {
                    title: t('HELO identity & STARTTLS'),
                    what: t('The SMTP banner should announce a fully-qualified hostname and the server must offer STARTTLS so mail is encrypted in transit.'),
                    fix: t('Configure the mail server hostname as a FQDN matching the PTR record and enable STARTTLS with a valid certificate.'),
                    optional: '',
                },
                dkim: {
                    title: t('DKIM signature'),
                    what: t('DKIM cryptographically signs outgoing mail. Receivers verify the signature against the public key in your DNS.'),
                    fix: t('Enable DKIM signing in Stalwart and publish the public key as TXT record <selector>._domainkey.<domain>.'),
                    optional: '',
                },
                spf_alignment: {
                    title: t('SPF alignment'),
                    what: t('For DMARC, SPF must not only pass – the envelope sender domain must match your domain (alignment).'),
                    fix: t('Send with a MAIL FROM address of your own domain and make sure the SPF record of that domain authorises the sending IP.'),
                    optional: '',
                },
                dkim_alignment: {
                    title: t('DKIM alignment'),
                    what: t('For DMARC, the DKIM signature must be made with d=<your domain> – a signature of a third-party domain does not count.'),
                    fix: t('Sign with your own domain in Stalwart (d=<domain>) instead of a provider domain.'),
                    optional: '',
                },
                one_click_unsub: {
                    title: t('One-Click-Unsubscribe'),
                    what: t('Google and Yahoo require bulk senders (5000+ mails/day) to include RFC 8058 List-Unsubscribe headers, otherwise mail is filtered.'),
                    fix: t('Add both "List-Unsubscribe" and "List-Unsubscribe-Post: List-Unsubscribe=One-Click" headers in your newsletter/bulk tool.'),
                    optional: t('Only relevant for newsletters/bulk mail. The reputation probe is transactional, so this cannot be fully evaluated automatically.'),
                },
                blacklist_ip: {
                    title: t('Blacklists (outbound IP)'),
                    what: t('The outbound IP is checked against 120+ DNS blacklists. Listings cause rejects at many receivers.'),
                    fix: t('Request delisting at the listed blacklists and fix the root cause first.'),
                    optional: '',
                },
                blacklist_domain: {
                    title: t('Blacklists (domain)'),
                    what: t('The domain itself is checked against domain blacklists (e.g. Spamhaus DBL).'),
                    fix: t('Request delisting and investigate why the domain was listed (compromised accounts, spam content, phishing).'),
                    optional: '',
                },
            }
            return map[id] || { title: id, what: '', fix: '', optional: '' }
        },
        problemOf(check) {
            const o = check.observed || {}
            const issues = {
                permissive_all:       t('The SPF record ends with +all/?all – it authorises the whole internet.'),
                too_many_lookups:     t('The SPF record needs more than 10 DNS lookups – receivers return permerror and treat SPF as failed.'),
                policy_none:          t('The DMARC policy is p=none – spoofed mail is neither quarantined nor rejected.'),
                no_rua:               t('The DMARC record has no rua= address – you receive no aggregate reports.'),
                partial_pct:          t('pct is below 100 – the policy is only applied to a fraction of mail.'),
                policy_unreachable:   t('The DNS record exists but the policy file could not be fetched over HTTPS.'),
                mode_not_enforce:     t('The MTA-STS policy is not in enforce mode – it does not protect against downgrade attacks yet.'),
                dmarc_not_enforcing:  t('BIMI requires DMARC p=quarantine or p=reject; the current policy is weaker.'),
                fcrdns_mismatch:      t('The PTR hostname does not resolve back to the outbound IP (FCrDNS broken).'),
                no_starttls:          t('The server does not offer STARTTLS – mail would be transferred unencrypted.'),
                banner_not_fqdn:      t('The SMTP banner does not announce a fully-qualified hostname.'),
                banner_ptr_mismatch:  t('The SMTP banner hostname differs from the PTR record.'),
                not_signed:           t('The test mail carried no DKIM signature.'),
                spf_not_pass:         t('SPF did not pass for the test mail.'),
                dkim_not_pass:        t('DKIM did not pass for the test mail.'),
                unaligned:            t('The authenticated domain does not match the customer domain – no DMARC alignment.'),
            }
            if (o.issue && issues[o.issue]) return issues[o.issue]
            if (check.id === 'spf_record' && !o.record) return t('No SPF record was found on the domain.')
            if (check.id === 'dmarc_policy' && !o.record) return t('No DMARC record was found at _dmarc.<domain>.')
            if (check.id === 'ptr' && !o.ptr) return t('The outbound IP has no PTR record at all.')
            if (check.id.startsWith('blacklist') && (o.listedCount || 0) > 0) {
                const names = (o.listed || []).map(l => l.name).join(', ')
                return t('Listed on {count} blacklist(s): {names}', { count: o.listedCount, names })
            }
            if (check.id === 'one_click_unsub' && o.list_unsubscribe && !o.one_click) {
                return t('List-Unsubscribe is present, but the One-Click header (List-Unsubscribe-Post) is missing.')
            }
            return t('The check did not pass – see the observed values below.')
        },
        nodataOf(check) {
            const reason = check.observed?.reason
            return {
                no_outbound_ip:         t('The outbound IP could not be determined yet – run a mail test first.'),
                no_completed_mail_test: t('No completed mail test yet – start one below to evaluate this check.'),
                no_probe_target:        t('Neither a Stalwart host nor an MX record could be probed.'),
                unreachable:            t('The SMTP probe could not reach the server on port 25 (possibly blocked).'),
                provider_error:         t('The reputation service could not be queried: {error}', { error: check.observed?.error || '' }),
                no_header_data:         t('The mail test result contains no header data.'),
            }[reason] || t('This check could not be evaluated.')
        },
        summaryOf(check) {
            const o = check.observed || {}
            if (check.id.startsWith('blacklist')) {
                if (check.status === 'nodata') return ''
                return o.listedCount > 0
                    ? t('{listed} of {total} lists', { listed: o.listedCount, total: o.totalChecked })
                    : t('clean on {total} lists', { total: o.totalChecked })
            }
            if (check.id === 'ptr') return o.ptr || ''
            if (check.id === 'dkim') return o.selector ? ('s=' + o.selector) : (o.result || '')
            if (check.id === 'mta_sts') return o.mode ? ('mode: ' + o.mode) : ''
            if (check.id === 'dmarc_policy') return o.p ? ('p=' + o.p) : ''
            if (check.id === 'helo_tls') return o.banner_host || ''
            return ''
        },
        observedRows(check) {
            const o = check.observed || {}
            const rows = []
            const add = (k, v) => { if (v !== null && v !== undefined && v !== '') rows.push({ k, v: String(v) }) }
            add(t('Record'), o.record)
            add('p=', o.p)
            add('rua=', o.rua)
            add('ruf=', o.ruf)
            add(t('Mode'), o.mode)
            add('IP', o.ip || o.target)
            add('PTR', o.ptr)
            add('FCrDNS', o.fcrdns !== undefined ? (o.fcrdns ? 'ok' : 'mismatch') : null)
            add(t('Banner'), o.banner_host)
            add('STARTTLS', o.starttls !== undefined ? (o.starttls ? t('offered') : t('not offered')) : null)
            add(t('Selector'), o.selector)
            add(t('SPF domain'), o.spf_domain)
            add(t('DKIM domain'), o.dkim_domain)
            if (Array.isArray(o.listed) && o.listed.length) {
                add(t('Listings'), o.listed.map(l => l.name + (l.category ? ` (${l.category})` : '')).join(', '))
            }
            return rows
        },
        async load(refresh) {
            this.loading = true
            try {
                this.data = await api.get('/api/reputation/checks', refresh ? { refresh: 1 } : {})
            } catch (e) {
                showError(api.errorMessage(e))
                this.data = null
            } finally {
                this.loading = false
            }
        },
    },
}
</script>

<style scoped>
.loading-row { display: flex; justify-content: center; padding: 32px; }
.checks-meta { font-size: .82rem; margin: 0 0 12px; }
.checks-meta code { background: var(--color-background-dark); padding: 1px 6px; border-radius: 4px; }

.check-list { list-style: none; margin: 0; padding: 0; border: 1px solid var(--color-border, var(--color-background-dark)); border-radius: var(--border-radius-large, 12px); overflow: hidden; }
.check-item + .check-item { border-top: 1px solid var(--color-border, var(--color-background-dark)); }
.check-item__row {
    display: flex; align-items: center; gap: 12px; width: 100%;
    padding: 12px 16px; background: transparent; border: 0; cursor: pointer; text-align: left;
    font: inherit; color: inherit;
}
.check-item__row:hover { background: var(--color-background-hover); }
.check-item__title { font-weight: 600; font-size: .9rem; }
.check-item__summary { font-size: .8rem; margin-left: auto; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 40%; }
.check-item__chevron { transition: transform .2s ease; flex-shrink: 0; }
.check-item__chevron.is-open { transform: rotate(180deg); }
.check-item__details { padding: 4px 16px 16px 16px; font-size: .87rem; background: var(--color-background-hover); }
.check-item__details p { margin: 6px 0; }
.check-item__what { color: var(--color-text-maxcontrast); }
.check-item__observed { display: grid; grid-template-columns: auto 1fr; gap: 4px 16px; margin-top: 10px; }
.check-item__observed dt { font-weight: 600; font-size: .78rem; color: var(--color-text-maxcontrast); }
.check-item__observed dd { margin: 0; overflow-wrap: anywhere; }
.check-item__observed code { background: var(--color-background-dark); padding: 1px 6px; border-radius: 4px; font-size: .78rem; }
</style>
