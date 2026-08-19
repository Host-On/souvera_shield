<template>
    <span v-if="!value" class="souvera-muted">—</span>
    <span v-else :class="['souvera-badge', cls]">{{ value.toUpperCase() }}</span>
</template>

<script>
export default {
    name: 'ResultBadge',
    props: { value: { type: String, default: '' } },
    computed: {
        cls() {
            if (this.value === 'pass') return 'souvera-badge--ok'
            if (this.value === 'fail') return 'souvera-badge--err'
            return 'souvera-badge--warn'
        },
    },
}
</script>
