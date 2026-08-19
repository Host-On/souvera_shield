#!/usr/bin/env python3
"""Prepend the v3.3.8 changelog block on doku.souvera.eu, book "Souvera Shield"."""
import json
import re
import urllib.request

TOKEN = "Token LoepNe3sx3MgGtq1ahI1lfBBwBUoDlgD:70xpPTerdxlcE5DvhJzfbykbxUAGK8Hy"
PAGE_ID = 75
BASE = "https://doku.souvera.eu/api"

NEW_BLOCK = """
<h2>v3.3.8 – 2026-02-16</h2>
<h3>Behoben</h3>
<ul>
  <li><strong>Icon-Farbe im Dashboard-Widget passte nicht zum Modus:</strong> Das Widget nutzte bisher das (weiße) Menü-Icon. Im hellen Modus war es dadurch unsichtbar; im dunklen Modus wurde es durch den Nextcloud-CSS-Filter falsch schwarz gefärbt. Das Widget zeigt jetzt ein eigenes, dunkles Icon (<code>img/dashboard.svg</code>), das Nextcloud im dunklen Modus automatisch in Weiß umkehrt.</li>
</ul>
<p>Ergebnis nach diesem Update:</p>
<ul>
  <li>Menü-Icon im hellen Modus: weiß · im dunklen Modus: schwarz (passt zum farbigen Souvera-Sidebar-Hintergrund).</li>
  <li>Dashboard-Widget-Icon im hellen Modus: schwarz · im dunklen Modus: weiß (passt zum normalen Widget-Hintergrund).</li>
</ul>

<hr>
"""

def main():
    req = urllib.request.Request(f"{BASE}/pages/{PAGE_ID}", headers={"Authorization": TOKEN})
    with urllib.request.urlopen(req) as r:
        page = json.load(r)
    html = page["html"]
    if "v3.3.8" in html:
        print("SKIP: v3.3.8 block already present.")
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
