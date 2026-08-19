<template>
    <NcEmptyContent :name="t('This page could not be loaded')"
                    :description="t('A part of the app failed to load. This usually means the app files on the server are incomplete after an update or the browser cached an old version. Please reload the page (Ctrl+F5). If the problem persists, run the Souvera Shield file check in the administration settings.')"
                    data-testid="view-load-error">
        <template #icon>
            <AlertCircleOutline :size="40" />
        </template>
        <template #action>
            <div class="view-load-error__action">
                <NcButton type="primary" data-testid="view-load-error-reload" @click="reload">
                    {{ t('Reload page') }}
                </NcButton>
                <p v-if="error" class="view-load-error__detail">{{ String(error) }}</p>
            </div>
        </template>
    </NcEmptyContent>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcButton       from '@nextcloud/vue/components/NcButton'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'

import { t } from '@/services/i18n'

/** Shown by the async-view wrapper when a lazy chunk fails to load. */
export default {
    name: 'ViewLoadError',
    components: { NcEmptyContent, NcButton, AlertCircleOutline },
    props: {
        error: { type: [Error, String, Object], default: null },
    },
    setup() { return { t } },
    methods: {
        reload() {
            window.location.reload()
        },
    },
}
</script>

<style scoped>
.view-load-error__action {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
}
.view-load-error__detail {
    margin: 0;
    font-size: .78rem;
    color: var(--color-text-maxcontrast);
    max-width: 480px;
    word-break: break-word;
}
</style>
