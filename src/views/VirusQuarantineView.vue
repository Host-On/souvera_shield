<template>
    <div>
        <header class="souvera-section__header">
            <div>
                <h1 class="souvera-heading-1">{{ t('Virus quarantine') }}</h1>
                <p class="souvera-section__sub">{{ t('Messages with infected attachments detected by the anti-virus.') }}</p>
            </div>
        </header>

        <QuarantineTable
            list-key="virus_quarantine"
            list-endpoint="/api/virus_quarantine"
            action-root="/api/virus_quarantine"
            export-endpoint="/api/virus_quarantine/export"
            :aria-label="t('Virus quarantine')" />
    </div>
</template>

<script>
import QuarantineTable from '@/components/QuarantineTable.vue'
import { t } from '@/services/i18n'

export default {
    name: 'VirusQuarantineView',
    components: { QuarantineTable },
    setup() { return { t } },
}
</script>
