<template>
    <div class="range-toolbar" data-testid="reputation-range-toolbar">
        <div class="segmented" role="tablist" data-testid="reputation-stats-range">
            <button v-for="opt in ranges" :key="opt.days"
                    type="button"
                    class="segmented__btn"
                    :class="{ 'is-active': modelValue === opt.days }"
                    :data-testid="`reputation-stats-range-${opt.days}`"
                    @click="$emit('update:modelValue', opt.days)">
                {{ opt.label }}
            </button>
        </div>
    </div>
</template>

<script>
import { t } from '@/services/i18n'

export default {
    name: 'RangeSwitcher',
    props: {
        modelValue: { type: Number, default: 30 },
    },
    emits: ['update:modelValue'],
    computed: {
        ranges() {
            return [
                { days: 7,  label: t('7 days')  },
                { days: 30, label: t('30 days') },
                { days: 90, label: t('90 days') },
            ]
        },
    },
}
</script>

<style scoped>
.range-toolbar {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 12px;
}

.segmented { display: inline-flex; border: 1px solid var(--color-border, var(--color-background-dark)); border-radius: var(--border-radius-large, 12px); overflow: hidden; background: var(--color-main-background); }
.segmented__btn {
    background: transparent; border: 0; padding: 8px 16px; font-size: .85rem; font-weight: 600;
    color: var(--color-text-maxcontrast); cursor: pointer;
    border-right: 1px solid var(--color-border, var(--color-background-dark));
}
.segmented__btn:last-child { border-right: 0; }
.segmented__btn:hover:not(.is-active) { background: var(--color-background-hover); color: var(--color-main-text); }
.segmented__btn.is-active { background: var(--color-primary-element); color: var(--color-primary-element-text); }
</style>
