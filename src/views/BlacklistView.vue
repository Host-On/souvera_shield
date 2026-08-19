<template>
    <div>
        <header class="souvera-section__header">
            <div>
                <h1 class="souvera-heading-1">{{ t('Blacklist') }}</h1>
                <p class="souvera-section__sub">{{ t('Senders or domains that should always be treated as spam for your account.') }}</p>
            </div>
        </header>

        <ListManager
            list-key="blacklist"
            list-endpoint="/api/blacklist"
            add-endpoint="/api/blacklist"
            remove-endpoint="/api/blacklist/remove"
            export-endpoint="/api/blacklist/export"
            :aria-label="t('Blacklist')" />
    </div>
</template>

<script>
import ListManager from '@/components/ListManager.vue'
import { t } from '@/services/i18n'

export default {
    name: 'BlacklistView',
    components: { ListManager },
    setup() { return { t } },
}
</script>
