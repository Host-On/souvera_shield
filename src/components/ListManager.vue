<template>
    <div class="listmgr" :data-testid="`listmgr-${listKey}`">
        <div class="listmgr__toolbar">
            <NcTextField
                :value="searchTerm"
                :label="t('Search…')"
                :label-visible="false"
                :placeholder="t('Search…')"
                :data-testid="`listmgr-search-${listKey}`"
                @update:value="searchTerm = $event" />
            <div class="listmgr__toolbar-actions">
                <NcButton type="primary"
                          :data-testid="`listmgr-add-${listKey}`"
                          @click="openAdd">
                    <template #icon><Plus :size="20" /></template>
                    {{ listKey === 'whitelist' ? t('Add whitelist entry') : t('Add blacklist entry') }}
                </NcButton>
                <NcButton type="tertiary"
                          :data-testid="`listmgr-export-${listKey}`"
                          @click="onExport">
                    <template #icon><Download :size="20" /></template>
                    {{ t('Export CSV') }}
                </NcButton>
            </div>
        </div>

        <div v-if="loading" class="listmgr__state" data-testid="listmgr-loading">
            <NcLoadingIcon :size="32" />
        </div>

        <NcEmptyContent v-else-if="!filteredEntries.length"
                        :name="searchTerm ? t('No matches.') : t('No entries yet.')"
                        :data-testid="`listmgr-empty-${listKey}`">
            <template #icon><FormatListBulleted /></template>
        </NcEmptyContent>

        <div v-else class="listmgr__table-wrap">
            <div class="listmgr__table-scroll">
                <table class="listmgr__table" :aria-label="ariaLabel">
                    <thead><tr>
                        <th>{{ t('Entry') }}</th>
                        <th class="listmgr__col-action">{{ t('Action') }}</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="entry in paginatedEntries" :key="entry">
                            <td>{{ entry }}</td>
                            <td class="listmgr__col-action">
                                <NcButton type="tertiary"
                                          :aria-label="t('Remove')"
                                          :title="t('Remove')"
                                          :data-testid="`listmgr-remove-${entry}`"
                                          @click="onRemove(entry)">
                                    <template #icon><Delete :size="18" /></template>
                                </NcButton>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pager
                :page="currentPage + 1"
                :page-count="totalPages"
                :total="filteredEntries.length"
                :per-page="pageSize"
                testid-prefix="listmgr-pager"
                @update:page="currentPage = $event - 1" />
        </div>

        <NcDialog v-if="prompt.open"
                  :name="prompt.title"
                  :buttons="promptButtons"
                  :data-testid="`listmgr-add-dialog-${listKey}`"
                  @update:open="prompt.open = $event">
            <NcTextField
                :value="prompt.value"
                :label="t('E-mail address or domain')"
                :data-testid="`listmgr-add-input-${listKey}`"
                placeholder="user@example.com"
                @update:value="prompt.value = $event"
                @keydown.enter="prompt.submit" />
        </NcDialog>

        <NcDialog v-if="confirm.open"
                  :name="confirm.title"
                  :buttons="confirmButtons"
                  :data-testid="`listmgr-confirm-dialog-${listKey}`"
                  @update:open="confirm.open = $event">
            <p>{{ confirm.message }}</p>
        </NcDialog>
    </div>
</template>

<script>
import NcButton        from '@nextcloud/vue/components/NcButton'
import NcTextField     from '@nextcloud/vue/components/NcTextField'
import NcEmptyContent  from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon   from '@nextcloud/vue/components/NcLoadingIcon'
import NcDialog        from '@nextcloud/vue/components/NcDialog'

import Plus                from 'vue-material-design-icons/Plus.vue'
import Delete              from 'vue-material-design-icons/Delete.vue'
import Download            from 'vue-material-design-icons/Download.vue'
import FormatListBulleted  from 'vue-material-design-icons/FormatListBulleted.vue'

import { showSuccess, showError } from '@nextcloud/dialogs'

import Pager from '@/components/Pager.vue'
import api from '@/services/api'
import { t } from '@/services/i18n'

/**
 * Reusable manager for the personal whitelist / blacklist. Backend
 * routes stay identical to v2.x.
 */
export default {
    name: 'ListManager',

    components: { NcButton, NcTextField, NcEmptyContent, NcLoadingIcon, NcDialog, Pager,
        Plus, Delete, Download, FormatListBulleted },

    props: {
        listKey:        { type: String, required: true },  // 'whitelist' | 'blacklist'
        listEndpoint:   { type: String, required: true },
        addEndpoint:    { type: String, required: true },
        removeEndpoint: { type: String, required: true },
        exportEndpoint: { type: String, required: true },
        ariaLabel:      { type: String, default: '' },
    },

    setup() {
        return { t }
    },

    data() {
        return {
            entries: [],
            loading: true,
            searchTerm: '',
            currentPage: 0,
            pageSize: 50,
            _prevFilterKey: '',
            prompt:  { open: false, title: '', value: '', submit: () => {} },
            confirm: { open: false, title: '', message: '', onConfirm: () => {} },
        }
    },

    computed: {
        filteredEntries() {
            const q = this.searchTerm.trim().toLowerCase()
            if (!q) return this.entries
            return this.entries.filter(e => String(e).toLowerCase().includes(q))
        },
        totalPages() {
            return Math.ceil(this.filteredEntries.length / this.pageSize) || 1
        },
        paginatedEntries() {
            if (this._prevFilterKey !== this.searchTerm) {
                this._prevFilterKey = this.searchTerm
                this.currentPage = 0
            }
            const start = this.currentPage * this.pageSize
            const end = start + this.pageSize
            const slice = this.filteredEntries.slice(start, end)
            // Clamp page if filtered set shrank (e.g. after removing entries)
            if (slice.length === 0 && this.currentPage > 0) {
                this.currentPage = Math.max(0, this.totalPages - 1)
                return this.filteredEntries.slice(this.currentPage * this.pageSize, (this.currentPage + 1) * this.pageSize)
            }
            return slice
        },
        promptButtons() {
            return [
                { label: t('Cancel'), type: 'secondary', callback: () => { this.prompt.open = false } },
                { label: t('OK'),     type: 'primary',   callback: () => this.prompt.submit() },
            ]
        },
        confirmButtons() {
            return [
                { label: t('Cancel'), type: 'secondary', callback: () => { this.confirm.open = false } },
                { label: t('OK'),     type: 'primary',   callback: () => {
                    const cb = this.confirm.onConfirm
                    this.confirm.open = false
                    cb()
                } },
            ]
        },
    },

    async mounted() {
        await this.load()
    },

    methods: {
        async load() {
            this.loading = true
            try {
                const data = await api.get(this.listEndpoint)
                const rows = Array.isArray(data) ? data : (data?.data || [])
                this.entries = rows.map(r => {
                    if (typeof r === 'string') return r
                    return r.address || r.email || r.value || r.entry || ''
                }).filter(Boolean)
            } catch (e) {
                showError(api.errorMessage(e))
                this.entries = []
            } finally {
                this.loading = false
            }
        },
        openAdd() {
            this.prompt = {
                open: true,
                title: this.listKey === 'whitelist' ? t('Add whitelist entry') : t('Add blacklist entry'),
                value: '',
                submit: async () => {
                    const value = (this.prompt.value || '').trim()
                    if (!value) return
                    this.prompt.open = false
                    try {
                        await api.post(this.addEndpoint, { entry: value })
                        showSuccess(t('Entry added.'))
                        await this.load()
                    } catch (e) {
                        showError(t('Could not add entry: ') + api.errorMessage(e))
                    }
                },
            }
        },
        onRemove(entry) {
            this.confirm = {
                open: true,
                title: t('Remove entry?'),
                message: entry,
                onConfirm: async () => {
                    try {
                        await api.post(this.removeEndpoint, { entry })
                        showSuccess(t('Removed.'))
                        await this.load()
                    } catch (e) {
                        showError(api.errorMessage(e))
                    }
                },
            }
        },
        async onExport() {
            const stamp = new Date().toISOString().slice(0, 19).replace(/[T:]/g, '-')
            await api.download(this.exportEndpoint, `shield-${this.listKey}-${stamp}.csv`)
        },
    },
}
</script>

<style scoped>
.listmgr__toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-bottom: 16px;
}
.listmgr__toolbar-actions { display: flex; gap: 8px; }
.listmgr__state {
    display: flex;
    justify-content: center;
    padding: 40px;
}

.listmgr__table-wrap {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large, 12px);
    overflow: hidden;
    margin-bottom: 12px;
}

.listmgr__table-scroll {
    overflow-x: auto;
}

.listmgr__table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
    color: var(--color-main-text);
}

.listmgr__table thead th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--color-text-maxcontrast);
    background: var(--color-background-dark);
    border-bottom: 1px solid var(--color-border);
}

.listmgr__table td {
    padding: 10px 16px;
    border-bottom: 1px solid var(--color-border);
    vertical-align: middle;
    word-break: break-word;
}

.listmgr__table tbody tr {
    transition: background-color .15s ease;
}
.listmgr__table tbody tr:hover {
    background: var(--color-background-hover);
}
.listmgr__table tbody tr:last-child td {
    border-bottom: none;
}

.listmgr__col-action { width: 5rem; text-align: right; }

@media (max-width: 640px) {
    .listmgr__table { font-size: .82rem; }
    .listmgr__table th,
    .listmgr__table td { padding: 8px 10px; }
}
</style>
