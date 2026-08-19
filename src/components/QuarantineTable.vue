<template>
    <div class="qtable" :data-testid="`qtable-${listKey}`">
        <div class="qtable__toolbar">
            <NcTextField
                :value="searchTerm"
                :label="t('Search…')"
                :label-visible="false"
                :placeholder="t('Search…')"
                data-testid="qtable-search"
                @update:value="searchTerm = $event" />
            <NcActions v-if="mailboxOptions.length > 1" class="qtable__mailbox-filter">
                <NcActionButton v-for="mb in mailboxOptions" :key="mb.value"
                    @click="mailboxFilter = mb.value"
                    :class="{ 'action--active': mailboxFilter === mb.value }">
                    {{ mb.label }}
                    <template #icon><Check v-if="mailboxFilter === mb.value" :size="16" /></template>
                </NcActionButton>
            </NcActions>
            <div class="qtable__toolbar-actions">
                <NcButton type="tertiary"
                          :data-testid="`qtable-export-${listKey}`"
                          @click="onExport">
                    <template #icon><Download :size="20" /></template>
                    {{ t('Export CSV') }}
                </NcButton>
            </div>
        </div>

        <div v-if="selectedIds.length" class="qtable__bulkbar" :data-testid="`qtable-bulkbar-${listKey}`">
            <span class="souvera-muted">{{ n('{count} selected', '{count} selected', selectedIds.length, { count: selectedIds.length }) }}</span>
            <NcButton type="primary" :data-testid="`qtable-bulk-release-${listKey}`" @click="onBulkRelease">
                <template #icon><Check :size="20" /></template>
                {{ t('Release selected') }}
            </NcButton>
            <NcButton type="error" :data-testid="`qtable-bulk-delete-${listKey}`" @click="onBulkDelete">
                <template #icon><Delete :size="20" /></template>
                {{ t('Delete selected') }}
            </NcButton>
        </div>

        <div v-if="loading" class="qtable__state" data-testid="qtable-loading">
            <NcLoadingIcon :size="32" />
        </div>

        <NcEmptyContent v-else-if="!filteredRows.length"
                        :name="searchTerm ? t('No matches.') : t('Nothing in quarantine.')"
                        :data-testid="`qtable-empty-${listKey}`">
            <template #icon><EmailCheck /></template>
        </NcEmptyContent>

        <div v-else class="qtable__table-wrap">
            <div class="qtable__table-scroll">
                <table class="qtable__table" :aria-label="ariaLabel">
                    <thead><tr>
                        <th class="qtable__col-check">
                            <input type="checkbox"
                                   :checked="allSelected"
                                   :aria-label="t('Select all')"
                                   :data-testid="`qtable-selectall-${listKey}`"
                                   @change="toggleAll">
                        </th>
                        <th class="qtable__col-time">{{ t('Received') }}</th>
                        <th class="qtable__col-from">{{ t('From') }}</th>
                        <th class="qtable__col-to">{{ t('To') }}</th>
                        <th class="qtable__col-subject">{{ t('Subject') }}</th>
                        <th class="qtable__col-action">{{ t('Action') }}</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="row in paginatedRows" :key="rowId(row)"
                            :class="{ 'qtable__row--selected': selectedIds.includes(rowId(row)) }">
                            <td class="qtable__col-check">
                                <input type="checkbox"
                                       :checked="selectedIds.includes(rowId(row))"
                                       :aria-label="t('Select all')"
                                       :data-testid="`qtable-select-${rowId(row)}`"
                                       @change="toggleRow(row)">
                            </td>
                            <td class="qtable__col-time" :data-label="t('Received')">{{ formatTime(row.time) }}</td>
                            <td class="qtable__col-from" :data-label="t('From')"><span class="qtable__trunc" :title="row.from">{{ row.from }}</span></td>
                            <td class="qtable__col-to" :data-label="t('To')"><span class="qtable__trunc" :title="row._pmail">{{ row._pmail || '' }}</span></td>
                            <td class="qtable__col-subject" :data-label="t('Subject')"><span class="qtable__trunc" :title="row.subject">{{ row.subject }}</span></td>
                            <td class="qtable__col-action">
                                <div class="qtable__actions">
                                    <NcButton type="tertiary"
                                              :aria-label="t('Preview')"
                                              :title="t('Preview')"
                                              :data-testid="`qtable-view-${rowId(row)}`"
                                              @click="onView(row)">
                                        <template #icon><Eye :size="18" /></template>
                                    </NcButton>
                                    <NcButton type="tertiary"
                                              :aria-label="t('Release')"
                                              :title="t('Release')"
                                              :data-testid="`qtable-release-${rowId(row)}`"
                                              @click="onRelease(row)">
                                        <template #icon><Check :size="18" /></template>
                                    </NcButton>
                                    <NcButton type="tertiary"
                                              :aria-label="t('Delete')"
                                              :title="t('Delete')"
                                              :data-testid="`qtable-delete-${rowId(row)}`"
                                              @click="onDelete(row)">
                                        <template #icon><Delete :size="18" /></template>
                                    </NcButton>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pager
                :page="currentPage + 1"
                :page-count="totalPages"
                :total="filteredRows.length"
                :per-page="pageSize"
                testid-prefix="qtable-pager"
                @update:page="currentPage = $event - 1" />
        </div>

        <NcDialog v-if="preview.open"
                  :name="t('Message preview')"
                  size="large"
                  :buttons="previewButtons"
                  data-testid="qtable-preview-dialog"
                  @update:open="preview.open = $event">
            <pre class="qtable__preview">{{ preview.body }}</pre>
        </NcDialog>

        <NcDialog v-if="confirm.open"
                  :name="confirm.title"
                  :buttons="confirmButtons"
                  data-testid="qtable-confirm-dialog"
                  @update:open="confirm.open = $event">
            <p>{{ confirm.message }}</p>
        </NcDialog>
    </div>
</template>

<script>
import NcButton        from '@nextcloud/vue/components/NcButton'
import NcTextField     from '@nextcloud/vue/components/NcTextField'
import NcActions       from '@nextcloud/vue/components/NcActions'
import NcActionButton  from '@nextcloud/vue/components/NcActionButton'
import NcEmptyContent  from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon   from '@nextcloud/vue/components/NcLoadingIcon'
import NcDialog        from '@nextcloud/vue/components/NcDialog'

import Eye         from 'vue-material-design-icons/Eye.vue'
import Check       from 'vue-material-design-icons/Check.vue'
import Delete      from 'vue-material-design-icons/Delete.vue'
import Download    from 'vue-material-design-icons/Download.vue'
import EmailCheck  from 'vue-material-design-icons/EmailCheck.vue'

import { showSuccess, showError } from '@nextcloud/dialogs'

import Pager from '@/components/Pager.vue'
import api from '@/services/api'
import { t, n } from '@/services/i18n'

/**
 * Generic quarantine table used for spam, file and virus quarantine.
 * The three lists share the identical shape (id, time, from, subject) –
 * we only vary the backend endpoints.
 */
export default {
    name: 'QuarantineTable',

    components: { NcButton, NcTextField, NcActions, NcActionButton, NcEmptyContent, NcLoadingIcon, NcDialog, Pager,
        Eye, Check, Delete, Download, EmailCheck },

    props: {
        listKey:      { type: String, required: true },   // 'quarantine'|'file_quarantine'|'virus_quarantine'
        listEndpoint: { type: String, required: true },
        actionRoot:   { type: String, required: true },   // e.g. '/api/quarantine'
        exportEndpoint: { type: String, required: true },
        ariaLabel:    { type: String, default: '' },
    },

    setup() {
        return { t, n }
    },

    data() {
        return {
            rows: [],
            loading: true,
            searchTerm: '',
            mailboxFilter: '',
            selectedIds: [],
            pageSize: 50,
            currentPage: 0,
            _prevFilterKey: '',
            preview: { open: false, body: '' },
            confirm: { open: false, title: '', message: '', onConfirm: () => {} },
        }
    },

    computed: {
        filteredRows() {
            let rows = this.rows
            if (this.mailboxFilter) {
                rows = rows.filter(r => (r._pmail || '') === this.mailboxFilter)
            }
            const q = this.searchTerm.trim().toLowerCase()
            if (q) {
                rows = rows.filter(r =>
                    (r.from || '').toLowerCase().includes(q)
                    || (r.subject || '').toLowerCase().includes(q)
                    || (r._pmail || '').toLowerCase().includes(q)
                    || String(r.time || '').includes(q)
                )
            }
            // Reset page when filters change
            if (this._prevFilterKey !== (this.mailboxFilter + '|' + this.searchTerm)) {
                this._prevFilterKey = this.mailboxFilter + '|' + this.searchTerm
                this.currentPage = 0
            }
            return rows
        },
        paginatedRows() {
            const start = this.currentPage * this.pageSize
            return this.filteredRows.slice(start, start + this.pageSize)
        },
        totalPages() {
            return Math.ceil(this.filteredRows.length / this.pageSize) || 1
        },
        mailboxOptions() {
            const seen = new Set()
            const opts = []
            for (const r of this.rows) {
                const email = r._pmail
                if (email && !seen.has(email)) {
                    seen.add(email)
                    opts.push({ value: email, label: email })
                }
            }
            opts.sort((a, b) => a.label.localeCompare(b.label))
            return opts
        },
        allSelected() {
            const ids = this.paginatedRows.map(r => this.rowId(r))
            return ids.length > 0 && ids.every(id => this.selectedIds.includes(id))
        },
        previewButtons() {
            return [{ label: t('Close'), type: 'secondary', callback: () => { this.preview.open = false } }]
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
        rowId(row) {
            return String(row.id ?? row.messageid ?? row.msgid ?? row.uid ?? '')
        },
        formatTime(ts) {
            if (!ts) return ''
            const num = Number(ts)
            if (Number.isNaN(num)) return String(ts)
            return new Date(num * 1000).toLocaleString()
        },
        async load() {
            this.loading = true
            this.currentPage = 0
            const safety = setTimeout(() => { this.loading = false }, 15000)
            try {
                const data = await api.get(this.listEndpoint, { limit: 200 })
                this.rows = Array.isArray(data) ? data : (data?.data || [])
            } catch (e) {
                showError(api.errorMessage(e))
                this.rows = []
            } finally {
                clearTimeout(safety)
                this.loading = false
            }
        },
        toggleRow(row) {
            const id = this.rowId(row)
            const idx = this.selectedIds.indexOf(id)
            if (idx === -1) this.selectedIds.push(id)
            else this.selectedIds.splice(idx, 1)
        },
        toggleAll(ev) {
            if (ev.target.checked) {
                this.selectedIds = this.filteredRows.map(r => this.rowId(r))
            } else {
                this.selectedIds = []
            }
        },
        async onView(row) {
            try {
                const data = await api.get(this.listEndpoint + '/view', { id: this.rowId(row) })
                this.preview.body = typeof data === 'string' ? data : (data?.content || JSON.stringify(data, null, 2))
                this.preview.open = true
            } catch (e) {
                showError(api.errorMessage(e))
            }
        },
        onRelease(row) {
            this.confirm = {
                open: true,
                title: t('Release message?'),
                message: t('The message will be delivered to your inbox.'),
                onConfirm: async () => {
                    try {
                        await api.post(this.actionRoot + '/release', { id: this.rowId(row), email: row._pmail || '' })
                        showSuccess(t('Released.'))
                        this.selectedIds = this.selectedIds.filter(id => id !== this.rowId(row))
                        await this.load()
                    } catch (e) { showError(api.errorMessage(e)) }
                },
            }
        },
        onDelete(row) {
            this.confirm = {
                open: true,
                title: t('Delete message?'),
                message: t('This action cannot be undone.'),
                onConfirm: async () => {
                    try {
                        await api.post(this.actionRoot + '/delete', { id: this.rowId(row), email: row._pmail || '' })
                        showSuccess(t('Deleted.'))
                        this.selectedIds = this.selectedIds.filter(id => id !== this.rowId(row))
                        await this.load()
                    } catch (e) { showError(api.errorMessage(e)) }
                },
            }
        },
        async onBulkRelease() {
            if (!this.selectedIds.length) return
            const ids = [...this.selectedIds]
            this.confirm = {
                open: true,
                title: t('Release message?') + ' (' + ids.length + ')',
                message: t('The message will be delivered to your inbox.'),
                onConfirm: async () => {
                    try {
                        const res = await api.post(this.actionRoot + '/release', { ids: ids.join(',') })
                        showSuccess(t('Released {count} messages.', { count: res?.success ?? ids.length }))
                        this.selectedIds = []
                        await this.load()
                    } catch (e) { showError(api.errorMessage(e)) }
                },
            }
        },
        async onBulkDelete() {
            if (!this.selectedIds.length) return
            const ids = [...this.selectedIds]
            this.confirm = {
                open: true,
                title: t('Delete message?') + ' (' + ids.length + ')',
                message: t('This action cannot be undone.'),
                onConfirm: async () => {
                    try {
                        const res = await api.post(this.actionRoot + '/delete', { ids: ids.join(',') })
                        showSuccess(t('Deleted {count} messages.', { count: res?.success ?? ids.length }))
                        this.selectedIds = []
                        await this.load()
                    } catch (e) { showError(api.errorMessage(e)) }
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
.qtable__toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-bottom: 16px;
}
.qtable__toolbar-actions { display: flex; gap: 8px; }
.qtable__bulkbar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    background: var(--color-primary-element-light, var(--color-background-hover));
    border-radius: var(--border-radius-large, 12px);
    margin-bottom: 12px;
}
.qtable__state {
    display: flex;
    justify-content: center;
    padding: 40px;
}

.qtable__table-wrap {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large, 12px);
    overflow: hidden;
    margin-bottom: 12px;
}

.qtable__table-scroll {
    overflow-x: auto;
}

.qtable__table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
    color: var(--color-main-text);
    min-width: 640px;
}

.qtable__table thead th {
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

.qtable__table td {
    padding: 10px 16px;
    border-bottom: 1px solid var(--color-border);
    vertical-align: middle;
    word-break: break-word;
}

.qtable__table tbody tr {
    transition: background-color .15s ease;
}
.qtable__table tbody tr:hover {
    background: var(--color-background-hover);
}
.qtable__table tbody tr:last-child td {
    border-bottom: none;
}
.qtable__table tbody tr.qtable__row--selected {
    background: var(--color-primary-element-light, rgba(var(--color-primary-element-rgb, 0, 130, 201), 0.08));
}

.qtable__col-check   { width: 2.8rem; }
.qtable__col-time    { width: 11rem;  white-space: nowrap; font-variant-numeric: tabular-nums; }
.qtable__col-from    { width: 13rem;  max-width: 13rem; }
.qtable__col-to      { width: 14rem;  max-width: 14rem; }
.qtable__col-subject { width: auto;   min-width: 10rem; }
.qtable__col-action  { width: 9rem;   white-space: nowrap; text-align: right; }

.qtable__trunc {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 100%;
}

.qtable__actions {
    display: inline-flex;
    gap: 4px;
    justify-content: flex-end;
}

.qtable__preview {
    max-height: 60vh;
    overflow: auto;
    background: var(--color-background-hover);
    padding: 12px;
    border-radius: var(--border-radius, 8px);
    font-family: var(--font-face, monospace);
    font-size: .82rem;
    white-space: pre-wrap;
    word-break: break-word;
}

@media (max-width: 900px) {
    .qtable__toolbar { flex-wrap: wrap; gap: 6px; }
}
@media (max-width: 640px) {
    .qtable__table { font-size: .82rem; }
    .qtable__table th,
    .qtable__table td { padding: 8px 10px; }
}
</style>
