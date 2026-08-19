<template>
    <span :class="['souvera-badge', cls]" :title="error || ''">{{ label }}</span>
</template>

<script>
import { t } from '@/services/i18n'

export default {
    name: 'StatusBadge',
    props: {
        status: { type: String, required: true },
        error:  { type: String, default: '' },
    },
    computed: {
        cfg() {
            const map = {
                pending:   { cls: 'souvera-badge--warn', label: t('Waiting for mail')   },
                sent:      { cls: 'souvera-badge--warn', label: t('Waiting for result') },
                completed: { cls: 'souvera-badge--ok',   label: t('Completed')          },
                error:     { cls: 'souvera-badge--err',  label: t('Error')              },
            }
            return map[this.status] || { cls: 'souvera-badge--warn', label: this.status }
        },
        cls()   { return this.cfg.cls },
        label() { return this.cfg.label },
    },
}
</script>
