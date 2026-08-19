<template>
    <div>
        <header class="souvera-section__header">
            <div>
                <h1 class="souvera-heading-1">{{ t('File quarantine') }}</h1>
                <p class="souvera-section__sub">{{ t('Attachments held back by the content filter.') }}</p>
            </div>
        </header>

        <QuarantineTable
            list-key="file_quarantine"
            list-endpoint="/api/file_quarantine"
            action-root="/api/file_quarantine"
            export-endpoint="/api/file_quarantine/export"
            :aria-label="t('File quarantine')" />
    </div>
</template>

<script>
import QuarantineTable from '@/components/QuarantineTable.vue'
import { t } from '@/services/i18n'

export default {
    name: 'FileQuarantineView',
    components: { QuarantineTable },
    setup() { return { t } },
}
</script>
