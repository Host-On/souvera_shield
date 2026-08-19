#!/usr/bin/env python3
"""Prepend the v3.1.2 changelog block on doku.souvera.eu, book "Souvera Shield"."""
import json
import re
import urllib.request

TOKEN = "Token LoepNe3sx3MgGtq1ahI1lfBBwBUoDlgD:70xpPTerdxlcE5DvhJzfbykbxUAGK8Hy"
PAGE_ID = 75
BASE = "https://doku.souvera.eu/api"

NEW_BLOCK = """
<h2>v3.1.2 – 2026-02-16</h2>
<h3>Behoben</h3>
<ul>
  <li>Beim Aufruf des Nextcloud-Dashboards oder der globalen Suche (Strg+K) konnte es zu der Fehlermeldung „Could not resolve OCP\\IL10N! Class can not be instantiated?“ kommen. Ursache: Nextclouds Dependency-Injection-Container kann die Übersetzungs-Klasse nicht ohne App-Kontext auflösen. Das Dashboard-Widget und der Suchanbieter beziehen die Übersetzungen jetzt korrekt über die L10N-Factory – Dashboard und Suche funktionieren wieder zuverlässig.</li>
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

    # Insert immediately after the closing </p> of the legend paragraph.
    pat = re.compile(r'(</p>)', re.IGNORECASE)
    m = pat.search(html)
    if m is None:
        raise SystemExit("Could not find legend paragraph.")
    idx = m.end()
    new_html = html[:idx] + "\n" + NEW_BLOCK + html[idx:]

    if "v3.1.2" in html:
        print("SKIP: v3.1.2 block already present.")
        return
    status = put_page(new_html)
    print(f"OK: PUT status={status}")

if __name__ == "__main__":
    main()
