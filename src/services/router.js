/**
 * Featherweight URL-based router.
 *
 * The routes below are the paths *inside* the Souvera Shield app; the
 * absolute URL is built via @nextcloud/router's `generateUrl()` so
 * deployments on sub-paths (e.g. https://…/nextcloud/apps/…) keep
 * working without extra config.
 *
 * We deliberately avoid pulling in `vue-router` – the app only has nine
 * top-level views, no nested routes, no route params. Reactive
 * `currentRoute` is enough.
 */

import { reactive, computed } from 'vue'
import { generateUrl } from '@nextcloud/router'

// Route ID → path relative to `/apps/souvera_shield`.
export const ROUTES = Object.freeze({
    overview:         '/',
    quarantine:       '/quarantine',
    file_quarantine:  '/file_quarantine',
    virus_quarantine: '/virus_quarantine',
    whitelist:        '/whitelist',
    blacklist:        '/blacklist',
    dmarc:            '/dmarc',
    rep_providers:    '/reputation/providers',
    rep_checks:       '/reputation/checks',
    rep_sources:      '/reputation/sources',
    rep_incidents:    '/reputation/incidents',
    rep_mailtests:    '/reputation/mail-tests',
    suspicious_login: '/suspicious',
    settings:         '/settings',
    audit:            '/audit',
})

const state = reactive({
    current: (window.OCA?.SouveraShield?.initialPage) || 'overview',
})

function deriveFromLocation() {
    // Strip everything up to and including `/apps/souvera_shield` so we
    // work correctly on sub-path deployments.
    const marker = '/apps/souvera_shield'
    const path = window.location.pathname
    const idx = path.lastIndexOf(marker)
    if (idx === -1) return state.current
    const suffix = (path.slice(idx + marker.length) || '/').replace(/\/+$/, '') || '/'
    for (const [id, route] of Object.entries(ROUTES)) {
        if (route === suffix || (route === '/' && suffix === '/')) return id
    }
    return 'overview'
}

export const currentRoute = computed(() => state.current)

export function navigate(routeId) {
    if (!ROUTES[routeId]) return
    state.current = routeId
    const url = generateUrl('/apps/souvera_shield' + (ROUTES[routeId] === '/' ? '' : ROUTES[routeId]))
    window.history.pushState({ routeId }, '', url)
}

// Sync router on browser back/forward.
window.addEventListener('popstate', () => {
    state.current = deriveFromLocation()
})
