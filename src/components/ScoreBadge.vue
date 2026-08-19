<template>
    <span v-if="isError" class="souvera-badge souvera-badge--err">{{ t('Error') }}</span>
    <span v-else-if="value === null" class="souvera-muted">—</span>
    <span v-else :class="['souvera-badge', cls]">{{ value.toFixed(1) }}</span>
</template>

<script>
import { t } from '@/services/i18n'

export default {
    name: 'ScoreBadge',
    props: {
        score:  { type: [Number, String, null], default: null },
        status: { type: String, default: '' },
    },
    setup() { return { t } },
    computed: {
        isError() { return this.status === 'error' },
        value() {
            const n = Number(this.score)
            if (!Number.isFinite(n)) return null
            return n
        },
        cls() {
            if (this.value === null) return ''
            if (this.value >= 8) return 'souvera-badge--ok'
            if (this.value >= 5) return 'souvera-badge--warn'
            return 'souvera-badge--err'
        },
    },
}
</script>
