/**
 * Thin i18n helpers so components can just `import { t, n } from '@/services/i18n'`.
 * `@nextcloud/l10n` is the source of truth – we simply bind the app id.
 */
import { translate, translatePlural } from '@nextcloud/l10n'

const APP_ID = 'souvera_shield'

export function t(text, vars = undefined) {
    return translate(APP_ID, text, vars)
}

export function n(single, plural, count, vars = undefined) {
    return translatePlural(APP_ID, single, plural, count, vars)
}
