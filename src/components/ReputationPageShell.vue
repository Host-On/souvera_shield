<template>
    <div class="reputation-shell">
        <div v-if="loading" class="shell-loading" data-testid="reputation-shell-loading">
            <NcLoadingIcon :size="32" />
        </div>
        <template v-else-if="ready">
            <slot :domain="domain" :provider-status="providerStatus" />
        </template>
        <NcEmptyContent v-else
                        :name="t('Register your workspace domain first')"
                        :description="guardText"
                        data-testid="reputation-guard">
            <template #icon>
                <ShieldCheck :size="40" />
            </template>
            <template #action>
                <NcButton type="primary" data-testid="reputation-guard-goto" @click="navigate('dmarc')">
                    {{ t('Go to Score & analysis') }}
                </NcButton>
            </template>
        </NcEmptyContent>
    </div>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon  from '@nextcloud/vue/components/NcLoadingIcon'
import NcButton       from '@nextcloud/vue/components/NcButton'

import ShieldCheck from 'vue-material-design-icons/ShieldCheck.vue'

import { useReputationDomain } from '@/composables/useReputationDomain'
import { t } from '@/services/i18n'
import { navigate } from '@/services/router'

/**
 * Wraps a Reputation sub-page: loads the workspace domain, shows a
 * loading state and guards content that needs a registered
 * (`needs="domain"`) or provider-verified (`needs="verified"`) domain.
 */
export default {
    name: 'ReputationPageShell',
    components: { NcEmptyContent, NcLoadingIcon, NcButton, ShieldCheck },
    props: {
        needs: {
            type: String,
            default: 'domain',
            validator: v => ['domain', 'verified'].includes(v),
        },
    },
    setup() {
        const { domain, providerStatus, loading, reload } = useReputationDomain()
        return { t, navigate, domain, providerStatus, loading, reload }
    },
    computed: {
        ready() {
            if (!this.domain) return false
            return this.needs !== 'verified' || !!this.domain.provider_verified
        },
        guardText() {
            if (this.needs === 'verified' && this.domain) {
                return t('This section becomes available once the domain ownership has been verified.')
            }
            return t('The reputation pages become available once your workspace domain has been registered on the "Score & analysis" page.')
        },
    },
    async mounted() {
        await this.reload()
    },
}
</script>

<style scoped>
.shell-loading {
    display: flex;
    justify-content: center;
    padding: 48px 0;
}
</style>
