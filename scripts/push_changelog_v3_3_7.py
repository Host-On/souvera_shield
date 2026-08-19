#!/usr/bin/env python3
"""Prepend the v3.3.7 changelog block on doku.souvera.eu, book "Souvera Shield"."""
import json
import re
import urllib.request

TOKEN = "Token LoepNe3sx3MgGtq1ahI1lfBBwBUoDlgD:70xpPTerdxlcE5DvhJzfbykbxUAGK8Hy"
PAGE_ID = 75
BASE = "https://doku.souvera.eu/api"

NEW_BLOCK = """
<h2>v3.3.7 – 2026-02-16</h2>
<h3>Behoben</h3>
<ul>
  <li><strong>Dashboard-Widget zeigte dauerhaft einen Loading-Spinner:</strong> das Widget implementierte nur die alte Dashboard-API. Modernere Nextcloud-Versionen (27.1+) bleiben ohne die neue <code>IAPIWidgetV2</code>-Schnittstelle im Ladezustand hängen, wenn die Quarantäne leer ist. Das Widget liefert ab sofort einen expliziten Leer-Zustand („Deine Quarantäne ist derzeit leer.") und beendet damit den Spinner korrekt.</li>
  <li><strong>Kein Icon neben dem Widget-Titel:</strong> die Widget-CSS-Klasse verwies auf eine nicht existente Klasse. Die App liefert das Icon jetzt als absolute URL zum <code>appicon.svg</code>, sodass es in allen Themes (hell/dunkel/High-Contrast) und auf mobilen Clients sichtbar ist.</li>
</ul>
<h3>Neu</h3>
<ul>
  <li><strong>Dashboard-Widget ist ab jetzt standardmäßig eingeblendet.</strong> Neue Nutzerinnen und Nutzer sehen die Mail-Quarantäne direkt auf dem Dashboard – ohne sie erst manuell hinzufügen zu müssen. Bereits angepasste persönliche Dashboards bleiben unverändert.</li>
</ul>
<h3>Geändert</h3>
<ul>
  <li>Widget-Titel gekürzt von „Quarantäne in Souvera Shield" zu <strong>„Mail-Quarantäne"</strong> (bzw. „Mail Quarantine" / „Mail-quarantaine" in den anderen Sprachen).</li>
</ul>

<hr>
"""

def main():
    req = urllib.request.Request(f"{BASE}/pages/{PAGE_ID}", headers={"Authorization": TOKEN})
    with urllib.request.urlopen(req) as r:
        page = json.load(r)
    html = page["html"]
    if "v3.3.7" in html:
        print("SKIP: v3.3.7 block already present.")
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
