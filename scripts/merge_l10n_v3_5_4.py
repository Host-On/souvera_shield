#!/usr/bin/env python3
"""Add v3.5.4 file-integrity setup-check strings to all catalogues."""
import json
import os

L10N = '/app/l10n'

STRINGS = {
    'Souvera Shield: file integrity': {
        'de': 'Souvera Shield: Datei-Integrit\u00e4t',
        'de_DE': 'Souvera Shield: Datei-Integrit\u00e4t',
        'en_GB': 'Souvera Shield: file integrity',
        'nl': 'Souvera Shield: bestandsintegriteit',
    },
    'The file manifest of the app is missing (appinfo/manifest.json) \u2013 file integrity cannot be verified. Please redeploy the complete app folder.': {
        'de': 'Das Datei-Manifest der App fehlt (appinfo/manifest.json) \u2013 die Datei-Integrit\u00e4t kann nicht gepr\u00fcft werden. Bitte den kompletten App-Ordner erneut deployen.',
        'de_DE': 'Das Datei-Manifest der App fehlt (appinfo/manifest.json) \u2013 die Datei-Integrit\u00e4t kann nicht gepr\u00fcft werden. Bitte den kompletten App-Ordner erneut deployen.',
        'en_GB': 'The file manifest of the app is missing (appinfo/manifest.json) \u2013 file integrity cannot be verified. Please redeploy the complete app folder.',
        'nl': 'Het bestandsmanifest van de app ontbreekt (appinfo/manifest.json) \u2013 de bestandsintegriteit kan niet worden gecontroleerd. Implementeer de volledige app-map opnieuw.',
    },
    'The Souvera Shield installation is incomplete \u2013 %1$d file(s) are missing on this server: %2$s \u2013 Please redeploy the complete app folder and restart PHP-FPM afterwards.': {
        'de': 'Die Souvera-Shield-Installation ist unvollst\u00e4ndig \u2013 %1$d Datei(en) fehlen auf diesem Server: %2$s \u2013 Bitte den kompletten App-Ordner erneut deployen und anschlie\u00dfend PHP-FPM neu starten.',
        'de_DE': 'Die Souvera-Shield-Installation ist unvollst\u00e4ndig \u2013 %1$d Datei(en) fehlen auf diesem Server: %2$s \u2013 Bitte den kompletten App-Ordner erneut deployen und anschlie\u00dfend PHP-FPM neu starten.',
        'en_GB': 'The Souvera Shield installation is incomplete \u2013 %1$d file(s) are missing on this server: %2$s \u2013 Please redeploy the complete app folder and restart PHP-FPM afterwards.',
        'nl': 'De Souvera Shield-installatie is onvolledig \u2013 %1$d bestand(en) ontbreken op deze server: %2$s \u2013 Implementeer de volledige app-map opnieuw en herstart daarna PHP-FPM.',
    },
    '%1$d file(s) differ from the shipped release (possibly stale versions from an earlier update): %2$s \u2013 Please redeploy the complete app folder.': {
        'de': '%1$d Datei(en) weichen von der ausgelieferten Version ab (m\u00f6glicherweise veraltete Dateien eines fr\u00fcheren Updates): %2$s \u2013 Bitte den kompletten App-Ordner erneut deployen.',
        'de_DE': '%1$d Datei(en) weichen von der ausgelieferten Version ab (m\u00f6glicherweise veraltete Dateien eines fr\u00fcheren Updates): %2$s \u2013 Bitte den kompletten App-Ordner erneut deployen.',
        'en_GB': '%1$d file(s) differ from the shipped release (possibly stale versions from an earlier update): %2$s \u2013 Please redeploy the complete app folder.',
        'nl': '%1$d bestand(en) wijken af van de geleverde release (mogelijk verouderde versies van een eerdere update): %2$s \u2013 Implementeer de volledige app-map opnieuw.',
    },
    'All %1$d files of Souvera Shield v%2$s are present and unmodified.': {
        'de': 'Alle %1$d Dateien von Souvera Shield v%2$s sind vorhanden und unver\u00e4ndert.',
        'de_DE': 'Alle %1$d Dateien von Souvera Shield v%2$s sind vorhanden und unver\u00e4ndert.',
        'en_GB': 'All %1$d files of Souvera Shield v%2$s are present and unmodified.',
        'nl': 'Alle %1$d bestanden van Souvera Shield v%2$s zijn aanwezig en ongewijzigd.',
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
