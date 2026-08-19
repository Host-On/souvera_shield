#!/usr/bin/env python3
"""Append the v3.5.0 sections to admin manual page 91 (Book 12, 'Reputation Management')."""
import json
import urllib.request

TOKEN = "Token LoepNe3sx3MgGtq1ahI1lfBBwBUoDlgD:70xpPTerdxlcE5DvhJzfbykbxUAGK8Hy"
PAGE_ID = 91
BASE = "https://doku.souvera.eu/api"

NEW_SECTIONS = """
<h2>Neu ab v3.5.0: Der Reputations-Score (0–100)</h2>
<p>Ganz oben auf der Seite siehst du jetzt einen <strong>Gesamt-Score von 0 bis 100</strong>
in einem farbigen Ring (grün ≥ 80, orange ≥ 60, rot darunter). Er wird aus
fünf Komponenten berechnet – daneben siehst du für jede Komponente einen
Balken mit Einzelwert und Gewichtung:</p>
<table>
<thead><tr><th>Komponente</th><th>Gewicht</th><th>Datenquelle</th></tr></thead>
<tbody>
<tr><td><strong>DMARC-Reports</strong></td><td>30&nbsp;%</td><td>DKIM-/SPF-Pass-Raten aus den echten Reports der Empfänger</td></tr>
<tr><td><strong>Letzter Mail-Test</strong></td><td>25&nbsp;%</td><td>Punktzahl des jüngsten abgeschlossenen Tests</td></tr>
<tr><td><strong>Blacklists</strong></td><td>20&nbsp;%</td><td>120+ DNS-Blacklists – geprüft werden IP <em>und</em> Domain</td></tr>
<tr><td><strong>Infrastruktur-Checks</strong></td><td>15&nbsp;%</td><td>Ergebnis der Zustellbarkeits-Checks (siehe unten)</td></tr>
<tr><td><strong>Offene Vorfälle</strong></td><td>10&nbsp;%</td><td>Abzüge für offene kritische Vorfälle und Warnungen</td></tr>
</tbody>
</table>
<blockquote><p>:bulb: <strong>Keine erfundenen Zahlen:</strong> Komponenten ohne echte Datenbasis
(z.&nbsp;B. noch kein Mail-Test gelaufen) werden ausgegraut angezeigt und aus der
Berechnung ausgeschlossen. Gibt es noch gar keine Daten, zeigt die Seite das
ehrlich an, statt einen Wert zu schätzen.</p></blockquote>
<p>Über den Button <strong>„Analyse jetzt ausführen"</strong> startest du jederzeit einen
kompletten Analyse-Lauf (Checks, Score, Vorfälle). Automatisch passiert das
einmal täglich im Hintergrund. Unter dem Score siehst du den
<strong>Score-Verlauf</strong> der letzten Tage als Balken.</p>
<p><strong>[SCREENSHOT]</strong> Score-Ring mit Komponenten-Balken und Button „Analyse jetzt ausführen".</p>

<h2>Neu: Provider-Reputation (Google, Microsoft, Yahoo, GMX/Web.de)</h2>
<p>Vier Karten zeigen, wie die großen Mail-Provider deine Mails sehen –
berechnet aus den DMARC-Reports, die genau dieser Provider für deine Domain
geschickt hat: Nachrichten-Volumen, DKIM-&nbsp;und SPF-Pass-Rate und ein Urteil
(<strong>Gut</strong> / <strong>Gefährdet</strong> / <strong>Kritisch</strong>). Hat ein Provider im gewählten
Zeitraum keine Reports geschickt, steht dort „Keine Reports dieses Providers
im gewählten Zeitraum".</p>
<p><strong>[SCREENSHOT]</strong> Vier Provider-Karten mit Pass-Raten und Urteils-Pille.</p>

<h2>Neu: Zustellbarkeits-Checks mit Erklärung und Lösungsweg</h2>
<p>Eine aufklappbare Liste prüft alle wichtigen Standards: SPF-Record,
DMARC-Policy, SPF-/DKIM-Alignment, DKIM-Signatur, PTR/Reverse-DNS,
HELO-Banner &amp; STARTTLS, MTA-STS, TLS-RPT, BIMI, One-Click-Unsubscribe
sowie Blacklist-Prüfungen für IP und Domain.</p>
<ul>
<li>Jede Zeile hat eine Status-Pille: <strong>OK</strong>, <strong>Warnung</strong>, <strong>Fehlgeschlagen</strong>,
<strong>Optional</strong> oder <strong>Keine Daten</strong>.</li>
<li>Beim Aufklappen erklärt jeder Check verständlich, <strong>was das Problem ist</strong>
und <strong>wie du es behebst</strong> – inklusive der tatsächlich beobachteten Werte
(DNS-Record, IP, PTR, Listungen&nbsp;…).</li>
<li>Über <strong>„Checks neu ausführen"</strong> erzwingst du eine frische Prüfung
(sonst werden die Ergebnisse bis zu 6 Stunden zwischengespeichert).</li>
</ul>
<p><strong>[SCREENSHOT]</strong> Check-Liste mit einer aufgeklappten Warnung inkl. Problem/Lösungsweg.</p>

<h2>Neu: Versandquellen – legitim, unbekannt oder missbräuchlich</h2>
<p>Die Tabelle <strong>Versandquellen</strong> zeigt, wer im Namen deiner Domain Mails
verschickt – klassifiziert aus den echten DMARC-Daten:</p>
<ul>
<li><strong>Legitim</strong> – besteht SPF oder DKIM aligned (≥ 90&nbsp;%)</li>
<li><strong>Unbekannt</strong> – gemischte Ergebnisse, oft Weiterleitungen oder Mailinglisten</li>
<li><strong>Potenziell missbräuchlich</strong> – besteht beides nicht bei relevantem Volumen
(mögliches Spoofing/Phishing in deinem Namen)</li>
</ul>
<p>Erkennt die Analyse einen <strong>ungewöhnlichen Volumen-Anstieg</strong> (mögliches
kompromittiertes Konto), erscheint darüber ein deutlicher Warnhinweis.</p>
<p><strong>[SCREENSHOT]</strong> Quellen-Tabelle mit Klassifizierungs-Pillen.</p>

<h2>Neu: Reputations-Vorfälle mit Historie und Maßnahmen</h2>
<p>Erkannte Probleme (Blacklist-Listung, schwache DMARC-Pass-Rate, Anomalien,
fehlgeschlagene Checks oder Mail-Tests) werden automatisch als
<strong>Vorfall</strong> angelegt. Die Tabelle lässt sich nach <strong>Offen / Behoben / Alle</strong>
filtern. Der Detail-Dialog zeigt zu jedem Vorfall:</p>
<ul>
<li><strong>Was ist passiert</strong> – verständliche Ursache inkl. SMTP-Fehler-Diagnose</li>
<li><strong>Lösungsweg</strong> – konkrete Empfehlung</li>
<li><strong>Betroffen</strong> – Domain und ggf. IPs</li>
<li><strong>Historie &amp; Maßnahmen</strong> – wann erkannt, erneut erkannt, automatisch
oder manuell behoben</li>
</ul>
<p>Ist ein Zustand bei der nächsten Analyse nicht mehr feststellbar, wird der
Vorfall <strong>automatisch als behoben markiert</strong> (und bei Rückfall wieder
geöffnet – die Historie bleibt erhalten). Mit <strong>„Als behoben markieren"</strong>
kannst du Vorfälle auch manuell schließen.</p>
<p><strong>[SCREENSHOT]</strong> Vorfall-Dialog mit Maßnahmen-Historie und Behoben-Button.</p>

<h2>Neu: Beschwerden &amp; Feedback-Loops</h2>
<p>Unter dem Score siehst du, wie viele <strong>forensische DMARC-Reports (RUF)</strong>
eingegangen sind und ob eine RUF-Adresse im DMARC-Record veröffentlicht ist.
Echte Beschwerderaten von Google und Microsoft gibt es nur in deren eigenen
Portalen – registriere deine Domain dafür bei <strong>Google Postmaster Tools</strong>
und <strong>Microsoft SNDS</strong>. Souvera Shield zeigt hier bewusst keine geschätzten
Werte an.</p>

<h2>Geändert ab v3.5.0: So wird der Mail-Test versendet</h2>
<p>Der Reputations-Test läuft weiterhin über <strong>deinen Stalwart-Mailserver</strong>
(nur so misst provider.tools die echte Reputation deiner Infrastruktur).
Neu: Souvera Shield legt dafür automatisch das Postfach
<code>postmaster@&lt;deine-domain&gt;</code> in Stalwart an und versendet den Test
angemeldet über den Submission-Port. Du musst dafür nichts tun – schlägt
etwas fehl, siehst du in der Mail-Test-Historie eine verständliche Diagnose,
die genau benennt, was dein Hoster prüfen muss.</p>
"""


def main():
    req = urllib.request.Request(f"{BASE}/pages/{PAGE_ID}", headers={"Authorization": TOKEN})
    with urllib.request.urlopen(req) as r:
        page = json.load(r)
    html = page["html"]
    if "Reputations-Score (0–100)" in html:
        print("SKIP: v3.5.0 sections already present.")
        return
    new_html = html + "\n" + NEW_SECTIONS
    body = json.dumps({"html": new_html}).encode()
    req = urllib.request.Request(
        f"{BASE}/pages/{PAGE_ID}",
        data=body,
        method="PUT",
        headers={"Authorization": TOKEN, "Content-Type": "application/json"},
    )
    with urllib.request.urlopen(req) as r:
        print(f"OK: PUT status={r.status}")


if __name__ == "__main__":
    main()
