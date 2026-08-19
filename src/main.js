/**
 * Souvera Shield – Vue 3 bootstrap.
 *
 * Bridges the initial state (feature flags + version + initial page)
 * exposed via `data-*` attributes on the mount point into a globally
 * reachable `OCA.SouveraShield` namespace, so components can read them
 * synchronously in setup() without another API round-trip.
 *
 * See SOUVERA_DESIGN_SYSTEM.md §3.
 */
import { createApp } from 'vue'
import App from './App.vue'
import './styles/forms.css'
import './styles/responsive-tables.css'

const mountEl = document.getElementById('souvera-shield-app')
if (mountEl) {
    window.OCA = window.OCA || {}
    window.OCA.SouveraShield = {
        version:     mountEl.dataset.appVersion    || '0.0.0',
        initialPage: mountEl.dataset.initialPage   || 'overview',
        flags: {
            is_admin:               mountEl.dataset.isAdmin               === '1',
            is_souvera_admin:       mountEl.dataset.isSouveraAdmin        === '1',
            allow_file_quarantine:  mountEl.dataset.allowFileQuarantine   === '1',
            allow_virus_quarantine: mountEl.dataset.allowVirusQuarantine  === '1',
        },
    }
    createApp(App).mount('#souvera-shield-app')
}
