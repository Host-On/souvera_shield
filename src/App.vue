<template>
    <NcContent app-name="souvera_shield">
        <NcAppNavigation
            data-testid="shield-navigation"
            :aria-label="t('Souvera Shield navigation')">
            <template #list>
                <NcAppNavigationItem
                    v-for="item in mainNavItems"
                    :key="item.id"
                    :name="item.label"
                    :active="currentRoute === item.id"
                    :data-testid="`nav-${item.id}`"
                    @click="navigate(item.id)">
                    <template #icon>
                        <component :is="item.icon" :size="20" />
                    </template>
                </NcAppNavigationItem>

                <template v-if="reputationNavItems.length">
                    <NcAppNavigationCaption :name="t('Reputation')" data-testid="nav-caption-reputation" />
                    <NcAppNavigationItem
                        v-for="item in reputationNavItems"
                        :key="item.id"
                        :name="item.label"
                        :active="currentRoute === item.id"
                        :data-testid="`nav-${item.id}`"
                        @click="navigate(item.id)">
                        <template #icon>
                            <component :is="item.icon" :size="20" />
                        </template>
                    </NcAppNavigationItem>
                </template>

                <template v-if="securityNavItems.length">
                    <NcAppNavigationCaption :name="t('Sicherheit')" data-testid="nav-caption-security" />
                    <NcAppNavigationItem
                        v-for="item in securityNavItems"
                        :key="item.id"
                        :name="item.label"
                        :active="currentRoute === item.id"
                        :data-testid="`nav-${item.id}`"
                        @click="navigate(item.id)">
                        <template #icon>
                            <component :is="item.icon" :size="20" />
                        </template>
                    </NcAppNavigationItem>
                </template>

                <template v-if="adminNavItems.length">
                    <NcAppNavigationCaption :name="t('Administration')" data-testid="nav-caption-admin" />
                    <NcAppNavigationItem
                        v-for="item in adminNavItems"
                        :key="item.id"
                        :name="item.label"
                        :active="currentRoute === item.id"
                        :data-testid="`nav-${item.id}`"
                        @click="navigate(item.id)">
                        <template #icon>
                            <component :is="item.icon" :size="20" />
                        </template>
                    </NcAppNavigationItem>
                </template>
            </template>
        </NcAppNavigation>

        <NcAppContent>
            <div class="souvera-content" data-testid="shield-content">
                <component :is="currentView" />
                <footer class="souvera-shield-footer">
                    <span data-testid="shield-version">v{{ appVersion }}</span>
                    <span v-if="isReputationRoute" data-testid="shield-powered-by"> · powered by Provider.tools</span>
                </footer>
            </div>
        </NcAppContent>
    </NcContent>
</template>

<script>
import { markRaw, defineAsyncComponent, h } from 'vue'

import NcContent              from '@nextcloud/vue/components/NcContent'
import NcAppNavigation        from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem    from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppContent           from '@nextcloud/vue/components/NcAppContent'
import NcLoadingIcon          from '@nextcloud/vue/components/NcLoadingIcon'

import ViewLoadError          from '@/components/ViewLoadError.vue'

import ViewDashboard          from 'vue-material-design-icons/ViewDashboard.vue'
import EmailAlert             from 'vue-material-design-icons/EmailAlert.vue'
import Paperclip              from 'vue-material-design-icons/Paperclip.vue'
import Bug                    from 'vue-material-design-icons/Bug.vue'
import CheckDecagram          from 'vue-material-design-icons/CheckDecagram.vue'
import CloseOctagon           from 'vue-material-design-icons/CloseOctagon.vue'
import Speedometer            from 'vue-material-design-icons/Speedometer.vue'
import Web                    from 'vue-material-design-icons/Web.vue'
import ListStatus             from 'vue-material-design-icons/ListStatus.vue'
import SourceBranch           from 'vue-material-design-icons/SourceBranch.vue'
import AlertCircleOutline     from 'vue-material-design-icons/AlertCircleOutline.vue'
import EmailFastOutline       from 'vue-material-design-icons/EmailFastOutline.vue'
import Security               from 'vue-material-design-icons/Security.vue'
import Cog                    from 'vue-material-design-icons/Cog.vue'
import FileDocumentOutline    from 'vue-material-design-icons/FileDocumentOutline.vue'

import { t } from '@/services/i18n'
import { currentRoute, navigate } from '@/services/router'

/**
 * The app shell follows SOUVERA_DESIGN_SYSTEM.md §2 verbatim:
 *   NcContent > NcAppNavigation (#list) + NcAppContent
 *
 * The navigation is grouped: general mail-protection pages on top,
 * the Reputation pages under an NcAppNavigationCaption (visible to
 * souvera-admins only) and Settings/Audit under "Administration".
 *
 * Feature-flags (`allow_file_quarantine`, `allow_virus_quarantine`, admin,
 * souvera-admin) are read from `OC.appConfig.souvera_shield` on boot – the
 * PHP template writes them there before mounting.
 */
export default {
    name: 'App',

    components: {
        NcContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationCaption, NcAppContent,
    },

    setup() {
        const flags = window.OCA?.SouveraShield?.flags || {}
        const appVersion = window.OCA?.SouveraShield?.version || '3.9.1'

        // Views are lazy-loaded so the initial bundle stays small.
        // If a chunk fails to load (incomplete update on the server,
        // stale browser cache, blocked request) we retry twice and then
        // show a visible error instead of a silent blank page.
        const ViewLoading = () => h(
            'div',
            { style: 'display:flex;justify-content:center;padding:48px 0;' },
            [h(NcLoadingIcon, { size: 32 })],
        )
        const lazyView = loader => defineAsyncComponent({
            loader,
            loadingComponent: ViewLoading,
            delay: 200,
            errorComponent: ViewLoadError,
            onError(error, retry, fail, attempts) {
                console.error(`[souvera_shield] Failed to load view chunk (attempt ${attempts})`, error)
                if (attempts <= 2) {
                    retry()
                } else {
                    fail()
                }
            },
        })

        const OverviewView            = lazyView(() => import('@/views/OverviewView.vue'))
        const QuarantineView          = lazyView(() => import('@/views/QuarantineView.vue'))
        const FileQuarantineView      = lazyView(() => import('@/views/FileQuarantineView.vue'))
        const VirusQuarantineView     = lazyView(() => import('@/views/VirusQuarantineView.vue'))
        const WhitelistView           = lazyView(() => import('@/views/WhitelistView.vue'))
        const BlacklistView           = lazyView(() => import('@/views/BlacklistView.vue'))
        const ReputationView          = lazyView(() => import('@/views/ReputationView.vue'))
        const ReputationProvidersView = lazyView(() => import('@/views/ReputationProvidersView.vue'))
        const ReputationChecksView    = lazyView(() => import('@/views/ReputationChecksView.vue'))
        const ReputationSourcesView   = lazyView(() => import('@/views/ReputationSourcesView.vue'))
        const ReputationIncidentsView = lazyView(() => import('@/views/ReputationIncidentsView.vue'))
        const ReputationMailTestsView = lazyView(() => import('@/views/ReputationMailTestsView.vue'))
        const SuspiciousLoginView     = lazyView(() => import('@/views/SuspiciousLoginView.vue'))
        const SettingsView            = lazyView(() => import('@/views/SettingsView.vue'))
        const AuditView               = lazyView(() => import('@/views/AuditView.vue'))

        const routeComponents = {
            overview:         OverviewView,
            quarantine:       QuarantineView,
            file_quarantine:  FileQuarantineView,
            virus_quarantine: VirusQuarantineView,
            whitelist:        WhitelistView,
            blacklist:        BlacklistView,
            dmarc:            ReputationView,
            rep_providers:    ReputationProvidersView,
            rep_checks:       ReputationChecksView,
            rep_sources:      ReputationSourcesView,
            rep_incidents:    ReputationIncidentsView,
            rep_mailtests:    ReputationMailTestsView,
            suspicious_login: SuspiciousLoginView,
            settings:         SettingsView,
            audit:            AuditView,
        }

        return {
            t,
            navigate,
            currentRoute,
            flags,
            appVersion,
            routeComponents,
        }
    },

    computed: {
        mainNavItems() {
            return [
                { id: 'overview',         label: t('Overview'),         icon: markRaw(ViewDashboard), show: true },
                { id: 'quarantine',       label: t('Spam quarantine'),  icon: markRaw(EmailAlert),    show: true },
                { id: 'file_quarantine',  label: t('File quarantine'),  icon: markRaw(Paperclip),     show: !!this.flags.allow_file_quarantine  },
                { id: 'virus_quarantine', label: t('Virus quarantine'), icon: markRaw(Bug),           show: !!this.flags.allow_virus_quarantine },
                { id: 'whitelist',        label: t('Whitelist'),        icon: markRaw(CheckDecagram), show: true },
                { id: 'blacklist',        label: t('Blacklist'),        icon: markRaw(CloseOctagon),  show: true },
            ].filter(i => i.show)
        },
        reputationNavItems() {
            if (!this.flags.is_souvera_admin) {
                return []
            }
            return [
                { id: 'dmarc',         label: t('Score & analysis'),      icon: markRaw(Speedometer) },
                { id: 'rep_providers', label: t('Provider reputation'),   icon: markRaw(Web) },
                { id: 'rep_checks',    label: t('Deliverability checks'), icon: markRaw(ListStatus) },
                { id: 'rep_sources',   label: t('Sending sources'),       icon: markRaw(SourceBranch) },
                { id: 'rep_incidents', label: t('Incidents'),             icon: markRaw(AlertCircleOutline) },
                { id: 'rep_mailtests',    label: t('Mail tests'),            icon: markRaw(EmailFastOutline) },
            ]
        },
        securityNavItems() {
            // "Sicherheit" section: visible to all authenticated users.
            // Admins see all events; regular users see only their own.
            return [
                { id: 'suspicious_login', label: t('Login-Sicherheit'), icon: markRaw(Security) },
            ]
        },
        adminNavItems() {
            if (!this.flags.is_admin) {
                return []
            }
            return [
                { id: 'settings', label: t('Settings'),  icon: markRaw(Cog) },
                { id: 'audit',    label: t('Audit log'), icon: markRaw(FileDocumentOutline) },
            ]
        },
        currentView() {
            return this.routeComponents[this.currentRoute] || this.routeComponents.overview
        },
        isReputationRoute() {
            return ['dmarc', 'rep_providers', 'rep_checks', 'rep_sources', 'rep_incidents', 'rep_mailtests']
                .includes(this.currentRoute)
        },
    },
}
</script>

<style scoped>
.souvera-shield-footer {
    margin-top: 48px;
    padding-top: 16px;
    border-top: 1px solid var(--color-border, var(--color-background-dark));
    color: var(--color-text-maxcontrast);
    font-size: .78rem;
    text-align: right;
}
</style>
