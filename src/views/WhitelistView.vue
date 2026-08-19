<template>
    <div>
        <header class="souvera-section__header">
            <div>
                <h1 class="souvera-heading-1">{{ t('Whitelist') }}</h1>
                <p class="souvera-section__sub">{{ t('Senders or domains that should always bypass the spam filter for your account.') }}</p>
            </div>
        </header>

        <ListManager
            list-key="whitelist"
            list-endpoint="/api/whitelist"
            add-endpoint="/api/whitelist"
            remove-endpoint="/api/whitelist/remove"
            export-endpoint="/api/whitelist/export"
            :aria-label="t('Whitelist')" />
    </div>
</template>

<script>
import ListManager from '@/components/ListManager.vue'
import { t } from '@/services/i18n'

export default {
    name: 'WhitelistView',
    components: { ListManager },
    setup() { return { t } },
}
</script>
