#!/usr/bin/env python3
"""Prepend the v3.4.2 changelog block on doku.souvera.eu, book "Souvera Shield"."""
import json
import re
import urllib.request

TOKEN = "Token LoepNe3sx3MgGtq1ahI1lfBBwBUoDlgD:70xpPTerdxlcE5DvhJzfbykbxUAGK8Hy"
PAGE_ID = 75
BASE = "https://doku.souvera.eu/api"

NEW_BLOCK = """
<h2>v3.4.2 – 2026-02-16</h2>
<h3>Behoben</h3>
<ul>
  <li><strong>Dashboard-Widget zeigte nie Quarantäne-Mails an, obwohl die App-Ansicht welche listete.</strong> Das Widget fragte PMG ohne Datumsbereich ab und bekam damit nur die Mails von <em>heute</em>. Ab sofort verwendet das Widget dasselbe 90-Tage-Fenster wie die reguläre Übersicht.</li>
  <li><strong>„Übersicht", „Datei-Quarantäne" und „Virus-Quarantäne" zeigten „Request failed with status code 404".</strong> Ursache: Das Frontend rief API-Adressen mit Bindestrich auf (<code>/api/file-quarantine</code>), während das Backend die Adressen mit Unterstrich anbietet (<code>/api/file_quarantine</code>). Frontend an das Backend angeglichen – alle drei Views laden jetzt sauber.</li>
</ul>

<hr>
"""

def main():
    req = urllib.request.Request(f"{BASE}/pages/{PAGE_ID}", headers={"Authorization": TOKEN})
    with urllib.request.urlopen(req) as r:
        page = json.load(r)
    html = page["html"]
    if "v3.4.2" in html:
        print("SKIP: v3.4.2 block already present.")
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
