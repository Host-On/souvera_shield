<template>
    <div>
        <header class="souvera-section__header">
            <div>
                <h1 class="souvera-heading-1">{{ t('Spam quarantine') }}</h1>
                <p class="souvera-section__sub">{{ t('Messages flagged by the spam filter and held for review.') }}</p>
            </div>
        </header>

        <QuarantineTable
            list-key="quarantine"
            list-endpoint="/api/quarantine"
            action-root="/api/quarantine"
            export-endpoint="/api/quarantine/export"
            :aria-label="t('Spam quarantine')" />
    </div>
</template>

<script>
import QuarantineTable from '@/components/QuarantineTable.vue'
import { t } from '@/services/i18n'

export default {
    name: 'QuarantineView',
    components: { QuarantineTable },
    setup() { return { t } },
}
</script>
