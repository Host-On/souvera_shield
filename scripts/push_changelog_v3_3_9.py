#!/usr/bin/env python3
"""Prepend the v3.3.9 changelog block on doku.souvera.eu, book "Souvera Shield"."""
import json
import re
import urllib.request

TOKEN = "Token LoepNe3sx3MgGtq1ahI1lfBBwBUoDlgD:70xpPTerdxlcE5DvhJzfbykbxUAGK8Hy"
PAGE_ID = 75
BASE = "https://doku.souvera.eu/api"

NEW_BLOCK = """
<h2>v3.3.9 – 2026-02-16</h2>
<h3>Behoben</h3>
<ul>
  <li><strong>Reputations-Mail-Test verlangte Konfiguration, die es gar nicht geben konnte.</strong> Souvera Shield ging bisher davon aus, dass unter <code>souvera_central.stalwart_admin_user</code> ein SMTP-fähiger Mail-Account hinterlegt ist – tatsächlich steht dort aber der Stalwart-Management-Nutzer (z. B. <code>admin</code>), der keinen Mail-Versand kann. Ergebnis: Der Test brach mit einer verwirrenden Konfig-Fehlermeldung ab.</li>
</ul>
<h3>Geändert</h3>
<ul>
  <li><strong>Der Mail-Test nutzt jetzt die gleichen SMTP-Einstellungen wie Nextcloud selbst.</strong> Die Werte, die der Administrator ohnehin für Passwort-Reset- und Benachrichtigungs-Mails unter „Verwaltung → Grundeinstellungen → E-Mail-Server" gesetzt hat, werden 1:1 übernommen (Server, Port, Verschlüsselung, Benutzername/Passwort, Absender-Adresse).</li>
  <li>Damit reflektiert der Reputations-Score exakt den Zustand des Servers, über den auch alle anderen Souvera-System-Mails laufen – kein Konfigurations-Setup mehr in Souvera Central nötig.</li>
</ul>
<h3>Entfernt</h3>
<ul>
  <li>Die früheren Config-Keys <code>souvera_central.stalwart_admin_user</code>, <code>souvera_central.stalwart_admin_password</code>, <code>souvera_central.stalwart_api_url</code>, <code>souvera_central.stalwart_smtp_port</code> werden für den Mail-Test nicht mehr gelesen. Souvera Central darf sie weiter für andere Zwecke nutzen; Shield ignoriert sie ab jetzt.</li>
</ul>
<p><em>Voraussetzung:</em> Nextclouds SMTP-Einstellungen müssen gesetzt sein (Verwaltung → Grundeinstellungen → E-Mail-Server). Ist das nicht der Fall, weist die Diagnose-Meldung darauf hin.</p>

<hr>
"""

def main():
    req = urllib.request.Request(f"{BASE}/pages/{PAGE_ID}", headers={"Authorization": TOKEN})
    with urllib.request.urlopen(req) as r:
        page = json.load(r)
    html = page["html"]
    if "v3.3.9" in html:
        print("SKIP: v3.3.9 block already present.")
        return
    m = re.search(r'</p>', html, re.IGNORECASE)
    if m is None:
        raise SystemExit("Could not find legend paragraph.")
    idx = m.end()
    new_html = html[:idx] + "\n" + NEW_BLOCK + html[idx:]
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
