#!/usr/bin/env python3
"""Prepend the v3.3.0 changelog block on doku.souvera.eu, book "Souvera Shield"."""
import json
import re
import urllib.request

TOKEN = "Token LoepNe3sx3MgGtq1ahI1lfBBwBUoDlgD:70xpPTerdxlcE5DvhJzfbykbxUAGK8Hy"
PAGE_ID = 75
BASE = "https://doku.souvera.eu/api"

NEW_BLOCK = """
<h2>v3.3.0 – 2026-02-16</h2>
<h3>Geändert</h3>
<ul>
  <li>Der Reputations-Mail-Test verschickt die Test-Mail nicht mehr über den generischen Nextcloud-Mailer, sondern über einen dedizierten SMTP-Relay-Kanal (Souvera Stalwart) mit eigenem Postmaster-App-Passwort. Damit werden externe Empfänger (z.&nbsp;B. <code>chk.provider.tools</code>) zuverlässig angenommen – der bisherige „Relay-Policy"-Fehler entfällt.</li>
  <li>Die Diagnose bei fehlgeschlagenem Test ist deutlich schärfer: sie zeigt jetzt die konkrete SMTP-Stufe an (<em>connect</em>, <em>starttls</em>, <em>auth</em>, <em>mail-from</em>, <em>rcpt-to</em>, <em>data</em>) und benennt den passenden Konfigurationsschlüssel, an dem der Hoster ansetzen muss.</li>
</ul>
<h3>Neu</h3>
<ul>
  <li>Neue zentrale Konfigurationsschlüssel in Souvera Central (App <code>souvera_central</code>): <code>settings.shield.mailtest_smtp_host</code>, <code>_port</code>, <code>_user</code>, <code>_password</code>, <code>_security</code> (<em>tls</em>/<em>ssl</em>/<em>none</em>, Standard <em>tls</em>) und optional <code>_from</code>. Ohne diese Konfiguration meldet Shield beim Mail-Test klar: <em>„Mail-Test-Relay nicht konfiguriert – bitte Hoster kontaktieren."</em></li>
</ul>

<hr>
"""

def main():
    req = urllib.request.Request(f"{BASE}/pages/{PAGE_ID}", headers={"Authorization": TOKEN})
    with urllib.request.urlopen(req) as r:
        page = json.load(r)
    html = page["html"]
    if "v3.3.0" in html:
        print("SKIP: v3.3.0 block already present.")
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
