<template>
    <section class="souvera-section" data-testid="reputation-domain">
        <header class="souvera-section__header">
            <div>
                <h2 class="souvera-heading-2">{{ t('Domain') }}</h2>
                <p class="souvera-section__sub">{{ t('Registration status of your workspace domain with the DMARC Analyzer.') }}</p>
            </div>
            <div class="souvera-section__actions">
                <NcButton v-if="loading" type="tertiary" disabled>
                    <NcLoadingIcon :size="18" />
                </NcButton>
                <NcButton v-else-if="!domain || !domain.is_registered"
                          type="primary"
                          data-testid="reputation-register-btn"
                          :disabled="!domain"
                          @click="onRegister">
                    {{ t('Register domain') }}
                </NcButton>
                <NcButton v-else-if="!domain.provider_verified"
                          type="primary"
                          data-testid="reputation-verify-btn"
                          @click="onVerify">
                    {{ t('Check verification') }}
                </NcButton>
                <NcButton v-else
                          type="tertiary"
                          data-testid="reputation-refresh-btn"
                          @click="$emit('refresh')">
                    <template #icon><Refresh :size="18" /></template>
                    {{ t('Refresh') }}
                </NcButton>
            </div>
        </header>

        <NcLoadingIcon v-if="loading" :size="32" class="loading-row" />

        <div v-else-if="domain" class="souvera-card">
            <dl class="repcard">
                <dt>{{ t('Domain') }}</dt>
                <dd><strong data-testid="reputation-domain-name">{{ domain.domain }}</strong></dd>

                <dt>{{ t('Sender for tests') }}</dt>
                <dd>{{ domain.sender_address }}</dd>

                <dt>{{ t('Analyzer status') }}</dt>
                <dd>
                    <span v-if="domain.provider_verified" class="souvera-badge souvera-badge--ok" data-testid="reputation-verified">
                        {{ t('Verified') }}
                    </span>
                    <span v-else-if="domain.is_registered" class="souvera-badge souvera-badge--warn" data-testid="reputation-unverified">
                        {{ t('Not verified') }}
                    </span>
                    <span v-else class="souvera-badge souvera-badge--warn" data-testid="reputation-notregistered">
                        {{ t('Not registered') }}
                    </span>
                </dd>

                <dt>{{ t('DMARC report inbox') }}</dt>
                <dd>{{ domain.report_email || '—' }}</dd>
            </dl>
        </div>

        <!-- Setup assistant (registered but not yet verified) -->
        <div v-if="domain && domain.is_registered && !domain.provider_verified" class="setup-box" data-testid="reputation-setup">
            <div class="setup-box__intro">
                <strong>{{ t('DNS setup required') }}</strong>
                <p>{{ t('Add the following TXT records at your DNS provider. After the records are visible, click "Check verification".') }}</p>
            </div>

            <ol class="setup-box__steps">
                <li v-if="domain.verification_txt" class="setup-box__step">
                    <div class="setup-box__num">1</div>
                    <div class="setup-box__body">
                        <div class="setup-box__title">{{ t('Publish the ownership verification record') }}</div>
                        <div class="setup-box__record">
                            <div class="setup-box__row">
                                <span class="setup-box__label">{{ t('Host') }}</span>
                                <div class="setup-box__val">
                                    <code>{{ domain.verification_host }}</code>
                                    <CopyIconButton :value="domain.verification_host" />
                                </div>
                            </div>
                            <div class="setup-box__row">
                                <span class="setup-box__label">{{ t('Type') }}</span>
                                <div class="setup-box__val">
                                    <code>TXT</code>
                                </div>
                            </div>
                            <div class="setup-box__row">
                                <span class="setup-box__label">{{ t('Value') }}</span>
                                <div class="setup-box__val">
                                    <code>{{ domain.verification_txt }}</code>
                                    <CopyIconButton :value="domain.verification_txt" />
                                </div>
                            </div>
                        </div>
                    </div>
                </li>
                <li v-if="domain.dmarc_record" class="setup-box__step">
                    <div class="setup-box__num">{{ domain.verification_txt ? 2 : 1 }}</div>
                    <div class="setup-box__body">
                        <div class="setup-box__title">{{ t('Publish (or update) the DMARC record') }}</div>
                        <div class="setup-box__record">
                            <div class="setup-box__row">
                                <span class="setup-box__label">{{ t('Host') }}</span>
                                <div class="setup-box__val">
                                    <code>{{ domain.dmarc_host }}</code>
                                    <CopyIconButton :value="domain.dmarc_host" />
                                </div>
                            </div>
                            <div class="setup-box__row">
                                <span class="setup-box__label">{{ t('Type') }}</span>
                                <div class="setup-box__val">
                                    <code>TXT</code>
                                </div>
                            </div>
                            <div class="setup-box__row">
                                <span class="setup-box__label">{{ t('Value') }}</span>
                                <div class="setup-box__val">
                                    <code>{{ domain.dmarc_record }}</code>
                                    <CopyIconButton :value="domain.dmarc_record" />
                                </div>
                            </div>
                        </div>
                    </div>
                </li>
            </ol>
        </div>
    </section>
</template>

<script>
import NcButton      from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Refresh       from 'vue-material-design-icons/Refresh.vue'

import CopyIconButton from '@/components/CopyIconButton.vue'
import { showSuccess, showError } from '@nextcloud/dialogs'

import api from '@/services/api'
import { t } from '@/services/i18n'

export default {
    name: 'ReputationDomain',
    components: { NcButton, NcLoadingIcon, Refresh, CopyIconButton },
    props: {
        domain:  { type: Object, default: null },
        loading: { type: Boolean, default: false },
    },
    emits: ['registered', 'verified', 'refresh'],
    setup() { return { t } },
    methods: {
        async onRegister() {
            showSuccess(t('Registering domain…'))
            try {
                await api.post('/api/dmarc/domain/register')
                showSuccess(t('Domain registered. Please publish the shown DNS records, then click "Check verification".'))
                this.$emit('registered')
            } catch (e) {
                showError(t('Registration failed') + ': ' + api.errorMessage(e))
            }
        },
        async onVerify() {
            showSuccess(t('Checking verification…'))
            try {
                const res = await api.post('/api/dmarc/domain/verify')
                const verified = !!res?.result?.verified
                const msg = res?.result?.message
                    || (verified ? t('Domain successfully verified.') : t('Verification TXT record not found yet – wait a few minutes and retry.'))
                if (verified) showSuccess(msg)
                else showError(msg)
                this.$emit('verified')
            } catch (e) {
                showError(t('Verification failed') + ': ' + api.errorMessage(e))
            }
        },
    },
}
</script>

<style scoped>
.loading-row { display: flex; justify-content: center; padding: 32px; }
.repcard {
    display: grid;
    grid-template-columns: minmax(140px, 220px) 1fr;
    gap: 12px 24px;
    margin: 0;
}
.repcard dt {
    color: var(--color-text-maxcontrast);
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    align-self: center;
}
.repcard dd { margin: 0; color: var(--color-main-text); }

/* DMARC DNS setup instructions */
.setup-box {
    margin-top: 20px;
    border: 1px dashed var(--color-warning, #f0a53c);
    border-radius: var(--border-radius-large, 12px);
    padding: 20px 22px;
    background: rgba(var(--color-warning-rgb, 240, 165, 60), 0.06);
}
.setup-box__intro strong { display: block; font-size: 1rem; margin-bottom: 4px; }
.setup-box__intro p { margin: 0 0 12px; color: var(--color-text-maxcontrast); font-size: .92rem; }
.setup-box__steps { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 14px; }
.setup-box__step { display: grid; grid-template-columns: 32px 1fr; gap: 12px; align-items: start; }
.setup-box__num {
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--color-primary-element); color: var(--color-primary-element-text);
    font-weight: 700; display: inline-flex; align-items: center; justify-content: center; font-size: .85rem;
}
.setup-box__body { min-width: 0; }
.setup-box__title { font-weight: 600; margin-bottom: 6px; color: var(--color-main-text); }
.setup-box__record {
    display: flex; flex-direction: column; gap: 6px;
    background: var(--color-main-background);
    border: 1px solid var(--color-border, var(--color-background-dark));
    border-radius: var(--border-radius, 8px);
    padding: 10px 12px;
}
.setup-box__row {
    display: flex; align-items: center; gap: 12px; min-width: 0;
}
.setup-box__label {
    flex: 0 0 6rem;
    color: var(--color-text-maxcontrast);
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    font-weight: 600;
    white-space: nowrap;
}
.setup-box__val {
    flex: 1 1 auto; min-width: 0;
    display: flex; align-items: center; gap: 8px;
}
.setup-box__val code {
    flex: 1 1 auto; min-width: 0;
    font-family: var(--font-face, monospace);
    background: var(--color-background-hover);
    padding: 3px 6px;
    border-radius: var(--border-radius, 6px);
    font-size: .82rem;
    word-break: break-all;
}

/* Narrow viewports: stack label above value so the code element gets
   the full row width and never gets clipped by the label column. */
@media (max-width: 640px) {
    .setup-box__row {
        flex-direction: column;
        align-items: stretch;
        gap: 4px;
    }
    .setup-box__label {
        flex: 0 0 auto;
    }
}
</style>
