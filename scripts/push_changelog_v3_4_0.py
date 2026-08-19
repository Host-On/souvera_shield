#!/usr/bin/env python3
"""Prepend the v3.4.0 changelog block on doku.souvera.eu, book "Souvera Shield"."""
import json
import re
import urllib.request

TOKEN = "Token LoepNe3sx3MgGtq1ahI1lfBBwBUoDlgD:70xpPTerdxlcE5DvhJzfbykbxUAGK8Hy"
PAGE_ID = 75
BASE = "https://doku.souvera.eu/api"

NEW_BLOCK = """
<h2>v3.4.0 – 2026-02-16</h2>
<h3>Behoben</h3>
<ul>
  <li><strong>Status-Chips (PASS/FAIL, Score, „Abgeschlossen", „Fehler", DKIM/SPF/DMARC) waren im hellen Modus kaum lesbar.</strong> Text- und Hintergrundfarbe hatten den gleichen Farbton mit sehr geringer Sättigung. Ab sofort werden Status-Chips mit einem satten Grün/Orange/Rot als Hintergrund und weißem Text dargestellt – klar erkennbar in beiden Modi.</li>
  <li><strong>Dashboard-Widget-Icon war komplett unsichtbar</strong> (leerer Rahmen anstelle des Schild-Symbols). Ursache: die separate SVG-Datei hatte eine leicht abweichende Zeichenfläche; jetzt wird sie 1:1 aus der Menü-Version abgeleitet (nur mit umgekehrter Farbe), sodass sie garantiert korrekt rendert.</li>
</ul>

<hr>
"""

def main():
    req = urllib.request.Request(f"{BASE}/pages/{PAGE_ID}", headers={"Authorization": TOKEN})
    with urllib.request.urlopen(req) as r:
        page = json.load(r)
    html = page["html"]
    if "v3.4.0" in html:
        print("SKIP: v3.4.0 block already present.")
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
