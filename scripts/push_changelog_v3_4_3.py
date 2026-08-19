#!/usr/bin/env python3
"""Prepend the v3.4.3 changelog block on doku.souvera.eu, book "Souvera Shield"."""
import json
import re
import urllib.request

TOKEN = "Token LoepNe3sx3MgGtq1ahI1lfBBwBUoDlgD:70xpPTerdxlcE5DvhJzfbykbxUAGK8Hy"
PAGE_ID = 75
BASE = "https://doku.souvera.eu/api"

NEW_BLOCK = """
<h2>v3.4.3 – 2026-02-16</h2>
<h3>Kritischer Fix</h3>
<ul>
  <li><strong>Der Reputations-Mail-Test läuft ab jetzt zwingend über den Stalwart-Server des Kunden.</strong> v3.3.9 hatte fälschlich Nextclouds eigene SMTP-Konfiguration verwendet – provider.tools misst dann die IP, PTR, SPF, DKIM und DMARC dieses generischen Servers und liefert damit ein völlig irrelevantes Ergebnis für die Kunden-Domain. Ab v3.4.3 verbindet sich Souvera Shield direkt mit dem Stalwart-Host aus <code>souvera_central.stalwart_api_url</code> und sendet die Test-Mail von <code>no-reply@&lt;Kunden-Domain&gt;</code> aus.</li>
  <li>Optionaler Port-Override über <code>souvera_central.stalwart_smtp_port</code> (Default: 25 – MTA-zu-MTA-Kanal, Trust-basierter Relay ohne SMTP-AUTH).</li>
  <li>Souvera Shield authentifiziert bewusst NICHT gegenüber Stalwart – der Stalwart-Management-Nutzer ist CLI-only und kann keine Mails versenden. Stattdessen wird die Nextcloud-IP als vertrauenswürdig behandelt (dieselbe Trust-Grenze, die auch Souvera Central selbst für seinen ausgehenden Mailverkehr nutzt).</li>
</ul>
<h3>Diagnose</h3>
<ul>
  <li>Fehlermeldungen verweisen jetzt auf Stalwart-Konfiguration (statt fälschlich auf Nextclouds Mail-Einstellungen). Beispiel: Wenn Stalwart AUTH erwartet, weist die Meldung darauf hin, dass die Nextcloud-IP als Trust-Relay eingetragen werden muss ODER der Port auf 25 gesetzt werden soll.</li>
</ul>

<hr>
"""

def main():
    req = urllib.request.Request(f"{BASE}/pages/{PAGE_ID}", headers={"Authorization": TOKEN})
    with urllib.request.urlopen(req) as r:
        page = json.load(r)
    html = page["html"]
    if "v3.4.3" in html:
        print("SKIP: v3.4.3 block already present.")
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
