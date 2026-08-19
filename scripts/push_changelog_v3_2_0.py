#!/usr/bin/env python3
"""Prepend the v3.2.0 changelog block on doku.souvera.eu, book "Souvera Shield"."""
import json
import re
import urllib.request

TOKEN = "Token LoepNe3sx3MgGtq1ahI1lfBBwBUoDlgD:70xpPTerdxlcE5DvhJzfbykbxUAGK8Hy"
PAGE_ID = 75
BASE = "https://doku.souvera.eu/api"

NEW_BLOCK = """
<h2>v3.2.0 – 2026-02-16</h2>
<h3>Geändert</h3>
<ul>
  <li>Die alle 10&nbsp;Minuten laufende Hintergrund-Abfrage nach neuen Quarantäne-Mails ist robuster: Wenn Proxmox Mail Gateway kurzzeitig nicht erreichbar ist (etwa während eines Neustarts oder eines TLS-Renewals), wiederholt Shield die Anfrage automatisch bis zu drei Mal mit ansteigender Wartezeit (200&nbsp;ms → 1&nbsp;s → 5&nbsp;s), bevor die aktuelle Runde übersprungen wird. Anwender sehen dadurch <strong>keine ausgelassenen Benachrichtigungen mehr</strong> bei kurzen PMG-Ausfällen.</li>
  <li>Wiederholt wird nur bei transienten Fehlern (5xx / Verbindungsabbruch). Dauerhafte Fehler wie „nicht autorisiert" (HTTP 401) oder „nicht gefunden" (HTTP 404) werden nicht wiederholt, um PMG nicht unnötig zu belasten.</li>
</ul>

<hr>
"""

def get_page():
    req = urllib.request.Request(f"{BASE}/pages/{PAGE_ID}", headers={"Authorization": TOKEN})
    with urllib.request.urlopen(req) as r:
        return json.load(r)

def put_page(html: str):
    body = json.dumps({"html": html}).encode()
    req = urllib.request.Request(
        f"{BASE}/pages/{PAGE_ID}",
        data=body,
        method="PUT",
        headers={"Authorization": TOKEN, "Content-Type": "application/json"},
    )
    with urllib.request.urlopen(req) as r:
        return r.status

def main():
    page = get_page()
    html = page["html"]
    if "v3.2.0" in html:
        print("SKIP: v3.2.0 block already present.")
        return

    pat = re.compile(r'(</p>)', re.IGNORECASE)
    m = pat.search(html)
    if m is None:
        raise SystemExit("Could not find legend paragraph.")
    idx = m.end()
    new_html = html[:idx] + "\n" + NEW_BLOCK + html[idx:]
    status = put_page(new_html)
    print(f"OK: PUT status={status}")

if __name__ == "__main__":
    main()
