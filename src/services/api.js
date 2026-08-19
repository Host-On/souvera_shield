/**
 * Thin wrapper around @nextcloud/axios so views don't have to worry about
 * base URLs, CSRF or Nextcloud's response envelope (`ocs?.data || data`).
 *
 * Backend routes are unchanged from v2.x – every call ends up at
 * `<REACT_APP_BACKEND_URL>/apps/souvera_shield/api/<something>` and is
 * protected by the group middlewares defined in AppInfo/Application.php.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Central response unwrapper as prescribed by the design system
 * (SOUVERA_DESIGN_SYSTEM.md §10). Souvera controllers may or may not
 * wrap the payload; this handles both shapes uniformly.
 */
function unwrap(response) {
    const body = response?.data ?? {}
    if (body?.ocs?.data !== undefined) return body.ocs.data
    if (body?.data !== undefined) return body.data
    return body
}

function url(path) {
    // Route paths from `appinfo/routes.php` are relative to /apps/souvera_shield.
    return generateUrl('/apps/souvera_shield' + (path.startsWith('/') ? path : '/' + path))
}

export default {
    async get(path, params = {}) {
        const response = await axios.get(url(path), { params, timeout: 30000 })
        return unwrap(response)
    },
    async post(path, body = {}) {
        const response = await axios.post(url(path), body)
        return unwrap(response)
    },
    async put(path, body = {}) {
        const response = await axios.put(url(path), body)
        return unwrap(response)
    },
    async del(path, params = {}) {
        const response = await axios.delete(url(path), { params })
        return unwrap(response)
    },
    /**
     * CSV / binary downloads: return a Blob so views can trigger a save.
     */
    async download(path, filename) {
        const response = await axios.get(url(path), { responseType: 'blob' })
        const blob = response.data
        const dl = document.createElement('a')
        dl.href = URL.createObjectURL(blob)
        dl.download = filename
        dl.click()
        setTimeout(() => URL.revokeObjectURL(dl.href), 5000)
    },
    /**
     * Turn an axios error into a human-readable message. The backend uses
     * `{ error: '…' }` for structured failures; fall back to HTTP text
     * when the payload doesn't follow that shape.
     */
    errorMessage(err) {
        const body = err?.response?.data
        if (body?.error) return body.error
        if (typeof body === 'string' && body.length < 500) return body
        return err?.message || 'Request failed'
    },
}
