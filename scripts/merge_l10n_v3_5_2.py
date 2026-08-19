#!/usr/bin/env python3
"""Add v3.5.2 provider-reputation empty/error state strings to all catalogues."""
import json
import os

L10N = '/app/l10n'

STRINGS = {
    'Not enough data yet': {
        'de': 'Noch nicht gen\u00fcgend Daten',
        'de_DE': 'Noch nicht gen\u00fcgend Daten',
        'en_GB': 'Not enough data yet',
        'nl': 'Nog niet genoeg gegevens',
    },
    'No DMARC reports were received for the selected period. Reports from the providers usually arrive within 24\u201348 hours after mail has been sent to them.': {
        'de': 'F\u00fcr den gew\u00e4hlten Zeitraum liegen keine DMARC-Reports vor. Reports der Provider treffen \u00fcblicherweise innerhalb von 24\u201348 Stunden ein, nachdem Mails an sie versendet wurden.',
        'de_DE': 'F\u00fcr den gew\u00e4hlten Zeitraum liegen keine DMARC-Reports vor. Reports der Provider treffen \u00fcblicherweise innerhalb von 24\u201348 Stunden ein, nachdem Mails an sie versendet wurden.',
        'en_GB': 'No DMARC reports were received for the selected period. Reports from the providers usually arrive within 24\u201348 hours after mail has been sent to them.',
        'nl': 'Er zijn geen DMARC-rapporten ontvangen voor de geselecteerde periode. Rapporten van providers komen doorgaans binnen 24\u201348 uur aan nadat er mail naar hen is verzonden.',
    },
    'Provider reputation could not be loaded': {
        'de': 'Provider-Reputation konnte nicht geladen werden',
        'de_DE': 'Provider-Reputation konnte nicht geladen werden',
        'en_GB': 'Provider reputation could not be loaded',
        'nl': 'Providerreputatie kon niet worden geladen',
    },
    'Try again': {
        'de': 'Erneut versuchen',
        'de_DE': 'Erneut versuchen',
        'en_GB': 'Try again',
        'nl': 'Opnieuw proberen',
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
