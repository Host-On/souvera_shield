<template>
    <div v-if="pageCount > 1 || showPageSize" class="pager" :aria-label="t('Pagination')" role="navigation">
        <div class="pager__info">
            {{ label }}
        </div>

        <div class="pager__controls">
            <NcButton type="tertiary"
                      :disabled="page <= 1"
                      :aria-label="t('First page')"
                      :data-testid="`${testidPrefix}-first`"
                      @click="$emit('update:page', 1)">
                <template #icon><ChevronDoubleLeft :size="18" /></template>
            </NcButton>

            <NcButton type="tertiary"
                      :disabled="page <= 1"
                      :aria-label="t('Previous page')"
                      :data-testid="`${testidPrefix}-prev`"
                      @click="$emit('update:page', page - 1)">
                <template #icon><ChevronLeft :size="18" /></template>
            </NcButton>

            <div class="pager__pages">
                <button v-for="p in visiblePages" :key="p"
                        type="button"
                        :class="['pager__page-num', { 'pager__page-num--active': p === page }]"
                        :data-testid="`${testidPrefix}-page-${p}`"
                        :aria-label="t('Page {num}', { num: p })"
                        @click="$emit('update:page', p)">
                    {{ p }}
                </button>
            </div>

            <NcButton type="tertiary"
                      :disabled="page >= pageCount"
                      :aria-label="t('Next page')"
                      :data-testid="`${testidPrefix}-next`"
                      @click="$emit('update:page', page + 1)">
                <template #icon><ChevronRight :size="18" /></template>
            </NcButton>

            <NcButton type="tertiary"
                      :disabled="page >= pageCount"
                      :aria-label="t('Last page')"
                      :data-testid="`${testidPrefix}-last`"
                      @click="$emit('update:page', pageCount)">
                <template #icon><ChevronDoubleRight :size="18" /></template>
            </NcButton>
        </div>

        <div v-if="showPageSize" class="pager__size">
            <label>
                {{ t('Per page') }}:
                <select v-model.number="localPerPage" @change="onPerPageChange">
                    <option v-for="opt in pageSizeOptions" :key="opt" :value="opt">{{ opt }}</option>
                </select>
            </label>
        </div>
    </div>
</template>

<script>
import NcButton          from '@nextcloud/vue/components/NcButton'
import ChevronLeft       from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight      from 'vue-material-design-icons/ChevronRight.vue'
import ChevronDoubleLeft  from 'vue-material-design-icons/ChevronDoubleLeft.vue'
import ChevronDoubleRight from 'vue-material-design-icons/ChevronDoubleRight.vue'

import { t } from '@/services/i18n'

export default {
    name: 'Pager',
    components: { NcButton, ChevronLeft, ChevronRight, ChevronDoubleLeft, ChevronDoubleRight },
    props: {
        page:          { type: Number, required: true },
        pageCount:     { type: Number, required: true },
        total:         { type: Number, required: true },
        perPage:       { type: Number, required: true },
        testidPrefix:  { type: String, default: 'pager' },
        /** When true, shows a per-page size selector (default false = legacy behaviour). */
        showPageSize:  { type: Boolean, default: false },
        /** Available per-page options when showPageSize is true. */
        pageSizeOptions: { type: Array, default: () => [10, 20, 50, 100] },
    },
    emits: ['update:page', 'update:perPage'],
    setup() { return { t } },
    data() {
        return { localPerPage: this.perPage }
    },
    computed: {
        label() {
            const from = this.total === 0 ? 0 : (this.page - 1) * this.perPage + 1
            const to = Math.min(this.total, this.page * this.perPage)
            return t('{from}–{to} of {total}', { from, to, total: this.total })
        },
        visiblePages() {
            const pages = []
            const total = this.pageCount
            const current = this.page
            const maxVisible = 5
            let start = Math.max(1, current - Math.floor(maxVisible / 2))
            let end = Math.min(total, start + maxVisible - 1)
            if (end - start + 1 < maxVisible) {
                start = Math.max(1, end - maxVisible + 1)
            }
            for (let i = start; i <= end; i++) {
                pages.push(i)
            }
            return pages
        },
    },
    watch: {
        perPage(newVal) { this.localPerPage = newVal },
    },
    methods: {
        onPerPageChange() {
            this.$emit('update:perPage', this.localPerPage)
        },
    },
}
</script>

<style scoped>
.pager {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    padding: 16px 4px 4px;
    flex-wrap: wrap;
}

.pager__info {
    color: var(--color-main-text);
    font-size: 13px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.pager__controls {
    display: flex;
    align-items: center;
    gap: 4px;
}

.pager__pages {
    display: flex;
    gap: 4px;
}

.pager__page-num {
    min-width: 36px;
    padding: 6px 10px;
    background: var(--color-main-background);
    border: 1.5px solid var(--color-border);
    border-radius: var(--border-radius, 6px);
    cursor: pointer;
    font-weight: 600;
    font-size: .82rem;
    color: var(--color-main-text);
    transition: all .15s ease;
}

.pager__page-num:hover {
    background: var(--color-background-hover);
    border-color: var(--color-primary-element);
    transform: translateY(-1px);
}

.pager__page-num--active {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    border-color: var(--color-primary-element);
    font-weight: 700;
    box-shadow: 0 2px 6px rgba(var(--color-primary-element-rgb, 0, 130, 201), 0.3);
}

.pager__size {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--color-main-text);
    font-weight: 600;
}

.pager__size select {
    padding: 4px 8px;
    border: 1.5px solid var(--color-border);
    border-radius: var(--border-radius, 6px);
    background: var(--color-main-background);
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    color: var(--color-main-text);
}

.pager__size select:focus {
    outline: none;
    border-color: var(--color-primary-element);
    box-shadow: 0 0 0 2px rgba(var(--color-primary-element-rgb, 0, 130, 201), 0.2);
}

@media (max-width: 768px) {
    .pager {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
    }
    .pager__controls {
        justify-content: center;
        flex-wrap: wrap;
    }
    .pager__info,
    .pager__size {
        text-align: center;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .pager {
        padding: 8px 0 0;
    }
    .pager__pages {
        display: none;
    }
}
</style>
