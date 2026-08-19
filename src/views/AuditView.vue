<template>
    <div>
        <header class="souvera-section__header">
            <div>
                <h1 class="souvera-heading-1">{{ t('Audit log') }}</h1>
                <p class="souvera-section__sub">{{ t('Audit log of mutating actions (release, delete, list changes).') }}</p>
            </div>
        </header>

        <div v-if="loading" class="loading-row">
            <NcLoadingIcon :size="32" />
        </div>

        <NcEmptyContent v-else-if="!entries.length"
                        :name="t('No entries yet.')"
                        data-testid="audit-empty">
            <template #icon><FileDocumentOutline /></template>
        </NcEmptyContent>

        <div v-else class="audit-table-wrap">
            <div class="audit-table-scroll">
                <table class="audit-table" :aria-label="t('Audit log')">
                    <thead><tr>
                        <th class="col-time">{{ t('When') }}</th>
                        <th class="col-who">{{ t('Who') }}</th>
                        <th class="col-what">{{ t('What') }}</th>
                        <th class="col-target">{{ t('Target') }}</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="row in paginatedEntries" :key="row.id" data-testid="audit-row">
                            <td class="col-time">{{ formatTime(row.created_at) }}</td>
                            <td class="col-who">{{ row.user_id }}</td>
                            <td class="col-what">{{ row.action }}</td>
                            <td class="col-target"><span class="audit-trunc" :title="row.target">{{ row.target }}</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pager
                :page="currentPage + 1"
                :page-count="totalPages"
                :total="entries.length"
                :per-page="pageSize"
                testid-prefix="audit-pager"
                @update:page="currentPage = $event - 1" />
        </div>
    </div>
</template>

<script>
import NcEmptyContent  from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon   from '@nextcloud/vue/components/NcLoadingIcon'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'

import { showError } from '@nextcloud/dialogs'

import Pager from '@/components/Pager.vue'
import api from '@/services/api'
import { t } from '@/services/i18n'

export default {
    name: 'AuditView',
    components: { NcEmptyContent, NcLoadingIcon, FileDocumentOutline, Pager },
    setup() { return { t } },
    data() { return {
        loading: true,
        entries: [],
        currentPage: 0,
        pageSize: 50,
    } },
    computed: {
        totalPages() {
            return Math.ceil(this.entries.length / this.pageSize) || 1
        },
        paginatedEntries() {
            const start = this.currentPage * this.pageSize
            return this.entries.slice(start, start + this.pageSize)
        },
    },
    async mounted() {
        try {
            const data = await api.get('/api/audit', { limit: 200 })
            this.entries = Array.isArray(data) ? data : (data?.data || [])
        } catch (e) {
            showError(api.errorMessage(e))
        } finally {
            this.loading = false
        }
    },
    methods: {
        formatTime(ts) {
            if (!ts) return ''
            return new Date(Number(ts) * 1000).toLocaleString()
        },
    },
}
</script>

<style scoped>
.loading-row { display: flex; justify-content: center; padding: 40px; }

.audit-table-wrap {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large, 12px);
    overflow: hidden;
    margin-bottom: 12px;
}

.audit-table-scroll {
    overflow-x: auto;
}

.audit-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
    color: var(--color-main-text);
}

.audit-table thead th {
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

.audit-table td {
    padding: 10px 16px;
    border-bottom: 1px solid var(--color-border);
    vertical-align: middle;
    word-break: break-word;
}

.audit-table tbody tr {
    transition: background-color .15s ease;
}
.audit-table tbody tr:hover {
    background: var(--color-background-hover);
}
.audit-table tbody tr:last-child td {
    border-bottom: none;
}

.col-time { width: 12rem; white-space: nowrap; font-variant-numeric: tabular-nums; }
.col-who  { width: 10rem; white-space: nowrap; }
.col-what { width: 12rem; white-space: nowrap; }
.col-target { width: auto; }

.audit-trunc {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 100%;
}

@media (max-width: 640px) {
    .audit-table { font-size: .82rem; }
    .audit-table th,
    .audit-table td { padding: 8px 10px; }
}
</style>
