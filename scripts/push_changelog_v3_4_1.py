#!/usr/bin/env python3
"""Prepend the v3.4.1 changelog block on doku.souvera.eu, book "Souvera Shield"."""
import json
import re
import urllib.request

TOKEN = "Token LoepNe3sx3MgGtq1ahI1lfBBwBUoDlgD:70xpPTerdxlcE5DvhJzfbykbxUAGK8Hy"
PAGE_ID = 75
BASE = "https://doku.souvera.eu/api"

NEW_BLOCK = """
<h2>v3.4.1 – 2026-02-16</h2>
<h3>Behoben</h3>
<ul>
  <li><strong>Status-Chips waren im hellen Modus immer noch blass.</strong> Der Fix in v3.4.0 benutzte Nextclouds Theme-Farbvariablen <code>--color-success/warning/error</code> als Hintergrund – die sind aber in NC's Standard-Light-Theme sehr pastellig, wodurch weißer Text darauf kaum lesbar war. Jetzt werden explizite, gesättigte HSL-Farben verwendet (kräftiges Grün / Bernstein / Rot), unabhängig vom NC-Theme, plus ein leichter Text-Shadow zur zusätzlichen Definition.</li>
  <li>Im dunklen Modus wird eine leicht hellere Variante genutzt, damit die Chips nicht überstrahlen.</li>
</ul>

<hr>
"""

def main():
    req = urllib.request.Request(f"{BASE}/pages/{PAGE_ID}", headers={"Authorization": TOKEN})
    with urllib.request.urlopen(req) as r:
        page = json.load(r)
    html = page["html"]
    if "v3.4.1" in html:
        print("SKIP: v3.4.1 block already present.")
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
