#!/usr/bin/env python3
"""Prepend the v3.5.0 changelog block on doku.souvera.eu, book "Souvera Shield"."""
import json
import re
import urllib.request

TOKEN = "Token LoepNe3sx3MgGtq1ahI1lfBBwBUoDlgD:70xpPTerdxlcE5DvhJzfbykbxUAGK8Hy"
PAGE_ID = 75
BASE = "https://doku.souvera.eu/api"

NEW_BLOCK = """
<h2>v3.5.0 – 2026-06-20</h2>
<h3>Kritischer Fix: Mail-Test-Versand (550 Relay not allowed)</h3>
<ul>
  <li><strong>Souvera Shield legt das Postfach <code>postmaster@&lt;Kunden-Domain&gt;</code> jetzt automatisch über die Stalwart-Management-API an</strong> (JMAP, <code>POST /api</code> mit den Admin-Credentials aus <code>souvera_central.stalwart_admin_user</code> / <code>…_admin_password</code>) und versendet den Reputations-Test per <strong>SMTP-AUTH als dieses Postfach</strong>. Damit akzeptiert Stalwart den externen provider.tools-Empfänger (authentifizierte Submission), und MAIL FROM = postmaster@Kunden-Domain sorgt für korrektes SPF-/DKIM-/DMARC-Alignment.</li>
  <li>Standard-Port ist jetzt <strong>587</strong> (Submission mit AUTH); Override weiterhin über <code>souvera_central.stalwart_smtp_port</code> (465 = Implicit-TLS).</li>
  <li>Das Postfach-Passwort wird zufällig generiert, verschlüsselt in der App-Config gespeichert und bei AUTH-Fehlern automatisch über die Management-API neu gesetzt (selbstheilend, genau ein Retry).</li>
  <li>Hinweis: Der frühere Versuch über <code>POST /api/principal</code> schlug fehl, weil Stalwart ≥ v0.16 die alte REST-Route entfernt hat – die Account-Verwaltung läuft dort über JMAP (<code>x:Account/set</code>).</li>
  <li>Neue Fehler-Stage <code>provision</code>: Diagnosen benennen exakt, ob die Management-API, die Domain-Anlage in Stalwart oder die Admin-Credentials das Problem sind.</li>
</ul>
<h3>Neu: Erweiterte Reputations-Verwaltung (alles echte Daten, kein Fake)</h3>
<ul>
  <li><strong>Zentraler Reputations-Score 0–100</strong> – gewichtet aus DMARC-Pass-Raten, letztem Mail-Test, Blacklist-Status (provider.tools, IP <em>und</em> Domain), Infrastruktur-Checks und offenen Vorfällen. Komponenten ohne Datenbasis werden ausgewiesen statt geschätzt; Score-Verlauf als tägliche Snapshots.</li>
  <li><strong>Provider-Reputation</strong> für Google, Microsoft, Yahoo und GMX/Web.de – Volumen und DKIM-/SPF-Pass-Raten aus den DMARC-Reports des jeweiligen Providers.</li>
  <li><strong>Zustellbarkeits-Checks</strong> mit Problem-Erklärung und Lösungsweg je Check: SPF-Record, DMARC-Policy, SPF-/DKIM-Alignment, DKIM-Signatur, PTR/FCrDNS, HELO-Banner, STARTTLS, MTA-STS (inkl. Policy-Abruf), TLS-RPT, BIMI, One-Click-Unsubscribe sowie Blacklist-Prüfung der ausgehenden IP und der Domain (120+ DNSBLs).</li>
  <li><strong>Quellen-Klassifizierung</strong> der DMARC-Versandquellen: legitim / unbekannt / potenziell missbräuchlich.</li>
  <li><strong>Anomalie-Erkennung</strong>: Volumen-Spikes (mögliche Konto-Kompromittierung) und Quellen ohne jegliche Authentifizierung.</li>
  <li><strong>Automatische Reputations-Vorfälle</strong> mit Historie, Ursache, betroffener Domain/IPs, Empfehlung und Maßnahmen-Protokoll; Dedupe, Auto-Resolve bei behobenem Zustand, Wiedereröffnung bei Rückfall, manuelles Auflösen im Dialog. Täglicher Hintergrund-Job + „Analyse jetzt ausführen“-Button.</li>
  <li><strong>Complaint-/Feedback-Loop-Auswertung</strong> – ehrlich: Anzahl forensischer DMARC-Reports (RUF) + RUF-Konfigurationsstatus; für Google/Microsoft-Beschwerderaten wird auf Postmaster Tools/SNDS verwiesen (keine öffentliche API, keine erfundenen Zahlen).</li>
  <li>Neue API-Endpunkte unter <code>/api/reputation/*</code> (overview, providers, checks, sources, incidents, analyze) – geschützt über die souvera-admins-Gruppe. Zwei neue Tabellen: <code>souvera_shield_incident</code>, <code>souvera_shield_score_snap</code>.</li>
  <li>Bestehende provider.tools-Integration, DMARC-Reports, SPF-/DKIM-Auswertung, Mail-Tests und das Seiten-Design bleiben unverändert.</li>
</ul>

<hr>
"""

def main():
    req = urllib.request.Request(f"{BASE}/pages/{PAGE_ID}", headers={"Authorization": TOKEN})
    with urllib.request.urlopen(req) as r:
        page = json.load(r)
    html = page["html"]
    if "v3.5.0" in html:
        print("SKIP: v3.5.0 block already present.")
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
