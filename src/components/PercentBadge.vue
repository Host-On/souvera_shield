<template>
    <span v-if="value === null" class="souvera-muted">—</span>
    <span v-else :class="['souvera-badge', badgeClass]">{{ value }}%</span>
</template>

<script>
export default {
    name: 'PercentBadge',
    props: { pct: { type: [Number, String, null], default: null } },
    computed: {
        value() {
            const n = Number(this.pct)
            if (!Number.isFinite(n)) return null
            return Math.max(0, Math.min(100, Math.round(n)))
        },
        badgeClass() {
            if (this.value === null) return ''
            if (this.value >= 95) return 'souvera-badge--ok'
            if (this.value >= 80) return 'souvera-badge--warn'
            return 'souvera-badge--err'
        },
    },
}
</script>
