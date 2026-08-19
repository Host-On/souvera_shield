#!/usr/bin/env python3
"""Prepend the v3.3.3 changelog block on doku.souvera.eu, book "Souvera Shield"."""
import json
import re
import urllib.request

TOKEN = "Token LoepNe3sx3MgGtq1ahI1lfBBwBUoDlgD:70xpPTerdxlcE5DvhJzfbykbxUAGK8Hy"
PAGE_ID = 75
BASE = "https://doku.souvera.eu/api"

NEW_BLOCK = """
<h2>v3.3.3 – 2026-02-16</h2>
<h3>Geändert</h3>
<ul>
  <li>Der Standard-SMTP-Port für den Mail-Test ist jetzt <strong>465 (SMTPS / implicit TLS)</strong> statt 587. Grund: Souvera Stalwart hört im Standard-Deployment auf 465 (analog zu Souvera Mail). Damit funktioniert der Mail-Test ab v3.3.3 <em>ohne</em> zusätzliche Port-Konfiguration.</li>
  <li>Der Config-Key <code>souvera_central.stalwart_smtp_port</code> bleibt bestehen – wer einen abweichenden Port nutzt (25 plain, 587 STARTTLS), setzt ihn per <code>occ config:system:set</code>.</li>
  <li>Bei Port-465-Verbindungen wird implicit-TLS mit relaxter Zertifikatsprüfung (self-signed toleriert) verwendet – funktioniert also weiterhin gegen interne Stalwart-Instanzen mit selbst-signiertem Zertifikat.</li>
</ul>

<hr>
"""

def main():
    req = urllib.request.Request(f"{BASE}/pages/{PAGE_ID}", headers={"Authorization": TOKEN})
    with urllib.request.urlopen(req) as r:
        page = json.load(r)
    html = page["html"]
    if "v3.3.3" in html:
        print("SKIP: v3.3.3 block already present.")
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
