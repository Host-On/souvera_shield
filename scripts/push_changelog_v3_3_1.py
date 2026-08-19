#!/usr/bin/env python3
"""Prepend the v3.3.1 changelog block on doku.souvera.eu, book "Souvera Shield"."""
import json
import re
import urllib.request

TOKEN = "Token LoepNe3sx3MgGtq1ahI1lfBBwBUoDlgD:70xpPTerdxlcE5DvhJzfbykbxUAGK8Hy"
PAGE_ID = 75
BASE = "https://doku.souvera.eu/api"

NEW_BLOCK = """
<h2>v3.3.1 – 2026-02-16</h2>
<h3>Geändert</h3>
<ul>
  <li>Der Reputations-Mail-Test übernimmt die Stalwart-Zugangsdaten jetzt <strong>vollautomatisch</strong> aus Souvera Central (drei bereits vorhandene <code>config.php</code>-Keys: <code>souvera_central.stalwart_api_url</code>, <code>_admin_user</code>, <code>_admin_password</code>). Es müssen keine zusätzlichen Nextcloud-App-Config-Keys mehr gesetzt und kein App-Passwort im Stalwart-Admin manuell erzeugt werden.</li>
  <li>Der SMTP-Host wird automatisch aus <code>stalwart_api_url</code> abgeleitet (Hostname), verwendet Port 587 mit STARTTLS und authentifiziert sich mit der Stalwart-Admin-Identität. Weil das Admin-Konto die <em>administrator</em>-Rolle trägt, sendet Shield pro Test aus der jeweils zu prüfenden Domäne (<code>postmaster@&lt;domain&gt;</code>) – identisch zu vorher, aber ohne Extra-Konto.</li>
  <li>Diagnose-Meldungen bei Fehlern nennen jetzt exakt die drei <code>stalwart_*</code>-Keys statt der bisherigen sechs <code>mailtest_smtp_*</code>-Keys.</li>
</ul>

<hr>
"""

def main():
    req = urllib.request.Request(f"{BASE}/pages/{PAGE_ID}", headers={"Authorization": TOKEN})
    with urllib.request.urlopen(req) as r:
        page = json.load(r)
    html = page["html"]
    if "v3.3.1" in html:
        print("SKIP: v3.3.1 block already present.")
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
