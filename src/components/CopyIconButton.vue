<template>
    <NcButton type="tertiary"
              :aria-label="t('Copy')"
              :title="t('Copy')"
              @click="copy">
        <template #icon><ContentCopy :size="16" /></template>
    </NcButton>
</template>

<script>
import NcButton    from '@nextcloud/vue/components/NcButton'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'

import { showSuccess, showError } from '@nextcloud/dialogs'
import { t } from '@/services/i18n'

export default {
    name: 'CopyIconButton',
    components: { NcButton, ContentCopy },
    props: { value: { type: String, required: true } },
    setup() { return { t } },
    methods: {
        async copy() {
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(this.value)
                } else {
                    const ta = document.createElement('textarea')
                    ta.value = this.value
                    document.body.appendChild(ta)
                    ta.select()
                    document.execCommand('copy')
                    document.body.removeChild(ta)
                }
                showSuccess(t('Copied to clipboard.'))
            } catch (e) {
                showError(t('Copy failed – please select the text manually.'))
            }
        },
    },
}
</script>
