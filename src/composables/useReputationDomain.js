/**
 * Shared loader for the workspace domain + provider.tools status.
 * Used by every Reputation view so each page is self-contained.
 */
import { ref } from 'vue'
import { showError } from '@nextcloud/dialogs'

import api from '@/services/api'

export function useReputationDomain() {
    const domain = ref(null)
    const providerStatus = ref(null)
    const loading = ref(true)

    async function reload() {
        loading.value = true
        try {
            const [status, dom] = await Promise.all([
                api.get('/api/dmarc/status'),
                api.get('/api/dmarc/domain'),
            ])
            providerStatus.value = status
            domain.value = dom
        } catch (e) {
            showError(api.errorMessage(e))
            domain.value = null
        } finally {
            loading.value = false
        }
    }

    return { domain, providerStatus, loading, reload }
}
