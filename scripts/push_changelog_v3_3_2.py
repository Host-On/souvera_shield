#!/usr/bin/env python3
"""Prepend the v3.3.2 changelog block on doku.souvera.eu, book "Souvera Shield"."""
import json
import re
import urllib.request

TOKEN = "Token LoepNe3sx3MgGtq1ahI1lfBBwBUoDlgD:70xpPTerdxlcE5DvhJzfbykbxUAGK8Hy"
PAGE_ID = 75
BASE = "https://doku.souvera.eu/api"

NEW_BLOCK = """
<h2>v3.3.2 – 2026-02-16</h2>
<h3>Geändert</h3>
<ul>
  <li>Der Mail-Test verträgt jetzt <strong>lokale Stalwart-Instanzen mit selbst-signiertem Zertifikat</strong>: die TLS-Verschlüsselung wird opportunistisch verhandelt – wenn Stalwart <code>STARTTLS</code> in der EHLO-Antwort anbietet, wird verschlüsselt (Zertifikatsprüfung deaktiviert, da interner Kontext), sonst wird plain weitergesendet. Kein Handshake-Absturz mehr an selbst-signierten Certs.</li>
  <li>Der SMTP-Port ist optional konfigurierbar über den neuen <code>config.php</code>-Key <code>souvera_central.stalwart_smtp_port</code> (Standard 587). Für Instanzen, die intern auf Port 25 lauschen, reicht ein einziger Eintrag.</li>
  <li>Port 465 (SMTPS/implicit TLS) wird weiterhin unterstützt – ebenfalls mit relaxter Zertifikatsprüfung, damit selbst-signierte Certs kein Blocker sind.</li>
</ul>
<h3>Neu</h3>
<ul>
  <li>Debug-Log-Zeilen für den Mail-Test: Fällt STARTTLS aus (weil vom Server nicht angeboten oder Handshake gescheitert), erscheint eine <em>warning</em>-Zeile im <code>nextcloud.log</code> mit Host/Port und dem konkreten Grund – ohne den Test-Ablauf abzubrechen.</li>
</ul>

<hr>
"""

def main():
    req = urllib.request.Request(f"{BASE}/pages/{PAGE_ID}", headers={"Authorization": TOKEN})
    with urllib.request.urlopen(req) as r:
        page = json.load(r)
    html = page["html"]
    if "v3.3.2" in html:
        print("SKIP: v3.3.2 block already present.")
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
