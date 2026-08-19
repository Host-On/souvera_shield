#!/usr/bin/env python3
"""Add v3.5.1 navigation/guard strings to all l10n catalogues (json + js)."""
import json
import os

L10N = '/app/l10n'

STRINGS = {
    'Score & analysis': {
        'de': 'Score & Analyse', 'de_DE': 'Score & Analyse',
        'en_GB': 'Score & analysis', 'nl': 'Score & analyse',
    },
    'Incidents': {
        'de': 'Vorf\u00e4lle', 'de_DE': 'Vorf\u00e4lle',
        'en_GB': 'Incidents', 'nl': 'Incidenten',
    },
    'Mail tests': {
        'de': 'Mail-Tests', 'de_DE': 'Mail-Tests',
        'en_GB': 'Mail tests', 'nl': 'Mailtests',
    },
    'Administration': {
        'de': 'Verwaltung', 'de_DE': 'Verwaltung',
        'en_GB': 'Administration', 'nl': 'Beheer',
    },
    'Register your workspace domain first': {
        'de': 'Zuerst die Workspace-Domain registrieren',
        'de_DE': 'Zuerst die Workspace-Domain registrieren',
        'en_GB': 'Register your workspace domain first',
        'nl': 'Registreer eerst uw workspace-domein',
    },
    'The reputation pages become available once your workspace domain has been registered on the "Score & analysis" page.': {
        'de': 'Die Reputations-Seiten stehen zur Verf\u00fcgung, sobald die Workspace-Domain auf der Seite \u201eScore & Analyse\u201c registriert wurde.',
        'de_DE': 'Die Reputations-Seiten stehen zur Verf\u00fcgung, sobald die Workspace-Domain auf der Seite \u201eScore & Analyse\u201c registriert wurde.',
        'en_GB': 'The reputation pages become available once your workspace domain has been registered on the "Score & analysis" page.',
        'nl': "De reputatiepagina's zijn beschikbaar zodra het workspace-domein is geregistreerd op de pagina 'Score & analyse'.",
    },
    'This section becomes available once the domain ownership has been verified with provider.tools.': {
        'de': 'Dieser Bereich steht zur Verf\u00fcgung, sobald der Domain-Besitz bei provider.tools verifiziert wurde.',
        'de_DE': 'Dieser Bereich steht zur Verf\u00fcgung, sobald der Domain-Besitz bei provider.tools verifiziert wurde.',
        'en_GB': 'This section becomes available once the domain ownership has been verified with provider.tools.',
        'nl': 'Deze sectie is beschikbaar zodra het domeineigendom is geverifieerd bij provider.tools.',
    },
    'Go to Score & analysis': {
        'de': 'Zu Score & Analyse', 'de_DE': 'Zu Score & Analyse',
        'en_GB': 'Go to Score & analysis', 'nl': 'Ga naar Score & analyse',
    },
}


def write_js(lang: str, translations: dict, plural_form: str) -> None:
    lines = ['OC.L10N.register(', '    "souvera_shield",', '    {']
    for key, value in translations.items():
        lines.append('        {}: {},'.format(json.dumps(key, ensure_ascii=False), json.dumps(value, ensure_ascii=False)))
    lines[-1] = lines[-1].rstrip(',')
    lines += ['},', json.dumps(plural_form) + ');']
    with open(os.path.join(L10N, f'{lang}.js'), 'w', encoding='utf-8') as fh:
        fh.write('\n'.join(lines) + '\n')


for lang in ('de', 'de_DE', 'en_GB', 'nl'):
    path = os.path.join(L10N, f'{lang}.json')
    with open(path, encoding='utf-8') as fh:
        data = json.load(fh)
    translations = data['translations']
    added = 0
    for key, per_lang in STRINGS.items():
        if key not in translations:
            translations[key] = per_lang[lang]
            added += 1
    data['translations'] = dict(sorted(translations.items()))
    with open(path, 'w', encoding='utf-8') as fh:
        json.dump(data, fh, ensure_ascii=False, indent=4)
        fh.write('\n')
    write_js(lang, data['translations'], data.get('pluralForm', 'nplurals=2; plural=(n != 1);'))
    print(f'{lang}: +{added} strings, total {len(translations)}')
