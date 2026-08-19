#!/usr/bin/env python3
"""Prepend the v3.3.4 changelog block on doku.souvera.eu, book "Souvera Shield"."""
import json
import re
import urllib.request

TOKEN = "Token LoepNe3sx3MgGtq1ahI1lfBBwBUoDlgD:70xpPTerdxlcE5DvhJzfbykbxUAGK8Hy"
PAGE_ID = 75
BASE = "https://doku.souvera.eu/api"

NEW_BLOCK = """
<h2>v3.3.4 – 2026-02-16</h2>
<h3>Neu</h3>
<ul>
  <li>Der Reputations-Mail-Test provisioniert seinen Send-Absender jetzt <strong>vollautomatisch</strong> im Stalwart. Beim ersten Test für eine Domain erzeugt Shield via Stalwart-Management-API einen dedizierten Principal <code>shield-mailtest@&lt;domain&gt;</code> (bcrypt-gehashtes Passwort), speichert die Zugangsdaten verschlüsselt in Shields eigenem App-Config und verwendet sie ab dann für den SMTP-Versand.</li>
  <li>Weil Shield unter der eigenen Identität des <em>shield-mailtest</em>-Principals sendet (MAIL FROM = seine eigene Adresse), gibt es keine sender-alignment-Probleme mehr – Stalwart akzeptiert den Versand ohne Sonder-Rechte oder Alias-Konfiguration.</li>
  <li>Falls Stalwart-Admin den Principal manuell entfernt, greift beim nächsten Test automatisch ein Reprovisioning (bei SMTP-AUTH-Fehler wird ein frischer Principal angelegt).</li>
</ul>
<h3>Geändert</h3>
<ul>
  <li>Die Diagnose-Meldungen wurden auf das neue Modell umgestellt: SMTP-AUTH-Fehler verweisen jetzt auf den <em>shield-mailtest</em>-Principal und die Management-API-Rechte des Admin-Users, nicht mehr auf den Admin selbst als Absender.</li>
</ul>
<h3>Sicherheit</h3>
<ul>
  <li>Der Principal-Passwort-Wert liegt im Nextcloud-App-Config <strong>verschlüsselt</strong> (Symmetric, über <code>OCP\\Security\\ICrypto</code>). Nur der Bcrypt-Hash landet im Stalwart, nicht der Klartext.</li>
</ul>

<hr>
"""

def main():
    req = urllib.request.Request(f"{BASE}/pages/{PAGE_ID}", headers={"Authorization": TOKEN})
    with urllib.request.urlopen(req) as r:
        page = json.load(r)
    html = page["html"]
    if "v3.3.4" in html:
        print("SKIP: v3.3.4 block already present.")
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
