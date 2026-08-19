<template>
    <div>
        <header class="souvera-section__header">
            <div>
                <h1 class="souvera-heading-1">{{ t('Settings') }}</h1>
                <p class="souvera-section__sub">{{ t('Control which quarantine areas users may access.') }}</p>
            </div>
        </header>

        <div v-if="loading" class="loading-row">
            <NcLoadingIcon :size="32" />
        </div>

        <div v-else class="souvera-card">
            <NcCheckboxRadioSwitch
                :checked="settings.allow_file_quarantine"
                data-testid="settings-file-quarantine"
                @update:checked="onToggle('allow_file_quarantine', $event)">
                {{ t('Enable file quarantine') }}
            </NcCheckboxRadioSwitch>

            <NcCheckboxRadioSwitch
                :checked="settings.allow_virus_quarantine"
                data-testid="settings-virus-quarantine"
                @update:checked="onToggle('allow_virus_quarantine', $event)">
                {{ t('Enable virus quarantine') }}
            </NcCheckboxRadioSwitch>
        </div>
    </div>
</template>

<script>
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon         from '@nextcloud/vue/components/NcLoadingIcon'

import { showSuccess, showError } from '@nextcloud/dialogs'

import api from '@/services/api'
import { t } from '@/services/i18n'

export default {
    name: 'SettingsView',
    components: { NcCheckboxRadioSwitch, NcLoadingIcon },
    setup() { return { t } },
    data() {
        return {
            loading: true,
            settings: {
                allow_file_quarantine: false,
                allow_virus_quarantine: false,
            },
        }
    },
    async mounted() {
        try {
            const data = await api.get('/api/settings')
            this.settings.allow_file_quarantine  = !!data.allow_file_quarantine
            this.settings.allow_virus_quarantine = !!data.allow_virus_quarantine
        } catch (e) {
            showError(t('Error loading settings'))
        } finally {
            this.loading = false
        }
    },
    methods: {
        async onToggle(key, value) {
            this.settings[key] = value
            try {
                await api.post('/api/settings', { [key]: value ? '1' : '0' })
                showSuccess(t('Settings saved.'))
            } catch (e) {
                showError(t('Could not save settings: ') + api.errorMessage(e))
                this.settings[key] = !value
            }
        },
    },
}
</script>

<style scoped>
.loading-row { display: flex; justify-content: center; padding: 40px; }
.souvera-card > * + * { margin-top: 12px; }
</style>
