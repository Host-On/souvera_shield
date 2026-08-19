#!/usr/bin/env python3
"""Prepend the v3.3.6 changelog block on doku.souvera.eu, book "Souvera Shield"."""
import json
import re
import urllib.request

TOKEN = "Token LoepNe3sx3MgGtq1ahI1lfBBwBUoDlgD:70xpPTerdxlcE5DvhJzfbykbxUAGK8Hy"
PAGE_ID = 75
BASE = "https://doku.souvera.eu/api"

NEW_BLOCK = """
<h2>v3.3.6 – 2026-02-16</h2>
<h3>Geändert</h3>
<ul>
  <li><strong>Kein zusätzlicher Setup-Schritt mehr:</strong> Souvera Shield verwendet für den Reputations-Mail-Test jetzt den <code>scadmin@</code>-Service-Account, den Souvera Central ohnehin bereits pflegt. Die in v3.3.5 verlangten Extra-Keys <code>souvera_central.stalwart_mailtest_user</code>/<code>_password</code> sind nicht mehr nötig.</li>
  <li>Diagnose-Meldungen bei fehlgeschlagener SMTP-Auth zeigen jetzt auf die drei Souvera-Central-Keys (<code>stalwart_api_url</code>, <code>stalwart_admin_user</code>, <code>stalwart_admin_password</code>) und weisen zusätzlich darauf hin, dass ein Fehler an dieser Stelle auch andere Souvera-Dienste betrifft — die Diagnose führt schneller zur Ursache.</li>
  <li>MAIL FROM ist ab sofort die scadmin-Adresse selbst. Stalwarts Sender-Alignment ist damit automatisch erfüllt (der Account sendet unter seiner eigenen Identität, keine 501-Rejects mehr).</li>
</ul>
<h3>Entfernt</h3>
<ul>
  <li>Die Config-Keys <code>souvera_central.stalwart_mailtest_user</code> und <code>souvera_central.stalwart_mailtest_password</code> (v3.3.5) werden nicht mehr gelesen. Sie können gefahrlos entfernt werden.</li>
</ul>
<h3>Behoben</h3>
<ul>
  <li><strong>501 Sender Rejection</strong> beim „Reputation jetzt testen"-Klick: Stalwart wies den Absender <code>postmaster@souvera.eu</code> zurück, weil der SMTP-AUTH-Nutzer diese Adresse nicht in seiner Identität führte. Fix: Absender = SMTP-AUTH-Nutzer selbst (scadmin@…).</li>
</ul>

<hr>
"""

def main():
    req = urllib.request.Request(f"{BASE}/pages/{PAGE_ID}", headers={"Authorization": TOKEN})
    with urllib.request.urlopen(req) as r:
        page = json.load(r)
    html = page["html"]
    if "v3.3.6" in html:
        print("SKIP: v3.3.6 block already present.")
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
