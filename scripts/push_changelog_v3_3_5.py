#!/usr/bin/env python3
"""Prepend the v3.3.5 changelog block on doku.souvera.eu, book "Souvera Shield"."""
import json
import re
import urllib.request

TOKEN = "Token LoepNe3sx3MgGtq1ahI1lfBBwBUoDlgD:70xpPTerdxlcE5DvhJzfbykbxUAGK8Hy"
PAGE_ID = 75
BASE = "https://doku.souvera.eu/api"

NEW_BLOCK = """
<h2>v3.3.5 – 2026-02-16</h2>
<h3>Geändert</h3>
<ul>
  <li><strong>Wichtige Korrektur:</strong> Stalwarts REST-Management-API wird seit v0.16 durch JMAP ersetzt — die in v3.3.4 versuchte Auto-Provisionierung via <code>POST /api/principal</code> funktioniert deshalb nicht (404). Statt weiterhin am REST-Endpoint zu bauen, geht Shield jetzt einen sauberen und ehrlichen Weg: Zwei zusätzliche Config-Keys, die der Hoster einmalig setzt.</li>
  <li>Neue Config-Keys in Souvera Central (<code>config.php</code>): <code>souvera_central.stalwart_mailtest_user</code> und <code>souvera_central.stalwart_mailtest_password</code>. Der Hoster erstellt einmalig einen Send-Account in Stalwarts Admin-UI (Principals → Add Individual, 2 Minuten) und trägt Zugangsdaten in die beiden Keys ein.</li>
  <li>Diagnose-Meldungen wurden auf das neue Modell umgestellt: alle Fehler-Stages (config, connect, starttls, auth, mail-from, rcpt-to, data) nennen jetzt entweder den passenden Config-Key oder den Admin-UI-Pfad.</li>
</ul>
<h3>Entfernt</h3>
<ul>
  <li>Klassen <code>StalwartAdminClient</code> und <code>MailTestPrincipalStore</code> (v3.3.4-Kern) wurden zurückgezogen — sie basieren auf einer nicht mehr existierenden REST-API. Die Tests dazu ebenfalls.</li>
</ul>

<hr>
"""

def main():
    req = urllib.request.Request(f"{BASE}/pages/{PAGE_ID}", headers={"Authorization": TOKEN})
    with urllib.request.urlopen(req) as r:
        page = json.load(r)
    html = page["html"]
    if "v3.3.5" in html:
        print("SKIP: v3.3.5 block already present.")
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
