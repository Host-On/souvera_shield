#!/usr/bin/env python3
"""
Merge the PHP-side l10n source strings introduced in v3.9.0 into all four
locale files. English is the source language; DE and NL carry hand-crafted
translations. Placeholders are preserved verbatim.

Run:  python3 scripts/merge_l10n_v3_9_0.py
"""
import json, pathlib, sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
L10N = ROOT / 'l10n'

# EN → DE + NL, hand-curated. Each entry is the exact English source
# string (as passed to $l10n->t()) followed by its DE and NL translation.
# Placeholders (%s, %d, %1$s, …) are copied verbatim to preserve order.
TRANSLATIONS: dict[str, dict[str, str]] = {
    # -------- Short common tokens --------
    'n/a': {
        'de': 'k. A.',
        'nl': 'n.v.t.',
    },
    'unknown': {
        'de': 'unbekannt',
        'nl': 'onbekend',
    },
    'unknown error': {
        'de': 'unbekannter Fehler',
        'nl': 'onbekende fout',
    },

    # -------- MailTestService: interpretMailerFailure --------
    'Reputation check could not be sent.': {
        'de': 'Reputationsprüfung konnte nicht versendet werden.',
        'nl': 'Reputatiecheck kon niet worden verzonden.',
    },
    'Error message: %s': {
        'de': '— Fehlermeldung: %s',
        'nl': '— Foutmelding: %s',
    },
    'Relay: %1$s:%2$d': {
        'de': 'Relay: %1$s:%2$d',
        'nl': 'Relay: %1$s:%2$d',
    },
    '(SMTP-AUTH as %s)': {
        'de': '(SMTP-AUTH als %s)',
        'nl': '(SMTP-AUTH als %s)',
    },
    '(anonymous / trusted-IP as %s)': {
        'de': '(anonym / Trusted-IP als %s)',
        'nl': '(anoniem / trusted-IP als %s)',
    },
    'The Stalwart server is not reachable via SMTP': {
        'de': 'Der Stalwart-Server ist per SMTP nicht erreichbar',
        'nl': 'De Stalwart-server is niet bereikbaar via SMTP',
    },
    '(tested ports: %s)': {
        'de': '(getestete Ports: %s)',
        'nl': '(geteste poorten: %s)',
    },
    'Important: the connection must work from the Nextcloud webserver process '
    '(not just from the shell or other apps). If the SMTP host differs from '
    'the API host (e.g. reverse proxy in front of souvera_central.stalwart_api_url), '
    'set souvera_central.stalwart_smtp_host; port via '
    'souvera_central.stalwart_smtp_port (default 587).': {
        'de': 'Wichtig: Die Verbindung muss vom Nextcloud-Webserver-Prozess aus möglich sein '
              '(nicht nur von der Shell oder anderen Apps). Weicht der SMTP-Host vom API-Host ab '
              '(z. B. Reverse-Proxy vor souvera_central.stalwart_api_url), '
              'souvera_central.stalwart_smtp_host setzen; Port via '
              'souvera_central.stalwart_smtp_port (Default 587).',
        'nl': 'Belangrijk: de verbinding moet vanuit het Nextcloud-webserverproces mogelijk zijn '
              '(niet alleen vanuit de shell of andere apps). Wijkt de SMTP-host af van de API-host '
              '(bijv. reverse proxy voor souvera_central.stalwart_api_url), '
              'stel dan souvera_central.stalwart_smtp_host in; poort via '
              'souvera_central.stalwart_smtp_port (standaard 587).',
    },
    'TLS handshake with Stalwart on port %d failed. Please verify the Stalwart '
    'certificate or adjust the port (souvera_central.stalwart_smtp_port).': {
        'de': 'TLS-Handshake mit Stalwart auf Port %d fehlgeschlagen. Bitte Stalwart-Zertifikat '
              'prüfen oder Port anpassen (souvera_central.stalwart_smtp_port).',
        'nl': 'TLS-handshake met Stalwart op poort %d mislukt. Controleer het Stalwart-certificaat '
              'of pas de poort aan (souvera_central.stalwart_smtp_port).',
    },
    'SMTP-AUTH as %s using the credentials from config.php '
    '(souvera_central.stalwart_mailtest_user / '
    'souvera_central.stalwart_mailtest_password) failed. '
    'Please verify the credentials – they must belong to an existing '
    'mailbox in the (external) directory of the Stalwart server.': {
        'de': 'SMTP-AUTH als %s mit den in config.php hinterlegten Zugangsdaten '
              '(souvera_central.stalwart_mailtest_user / '
              'souvera_central.stalwart_mailtest_password) fehlgeschlagen. '
              'Bitte die Zugangsdaten prüfen – sie müssen zu einem existierenden '
              'Postfach im (externen) Verzeichnis des Stalwart-Servers gehören.',
        'nl': 'SMTP-AUTH als %s met de in config.php ingestelde inloggegevens '
              '(souvera_central.stalwart_mailtest_user / '
              'souvera_central.stalwart_mailtest_password) mislukt. '
              'Controleer de inloggegevens – ze moeten bij een bestaand postvak '
              'in de (externe) directory van de Stalwart-server horen.',
    },
    'Stalwart requires SMTP-AUTH but Shield sends without AUTH (default since v3.8.0). '
    'The Nextcloud worker IP does not seem to be on the trusted-relay list of the '
    'Stalwart submission listener. Two options: (1) instruct CloudManager to add the '
    'Nextcloud IP as a trusted source on the Stalwart listener on port %d, or '
    '(2) set souvera_central.stalwart_mailtest_user and '
    'souvera_central.stalwart_mailtest_password in the Nextcloud config.php as a '
    'transitional workaround.': {
        'de': 'Stalwart verlangt SMTP-AUTH, obwohl Shield ohne AUTH sendet (Default seit v3.8.0). '
              'Die Nextcloud-Worker-IP scheint nicht auf der Trusted-Relay-Liste des Stalwart-'
              'Submission-Listeners zu stehen. Zwei Optionen: (1) CloudManager anweisen, die '
              'Nextcloud-IP am Stalwart-Listener auf Port %d als Trusted-Source einzutragen, oder '
              '(2) übergangsweise die Config-Keys souvera_central.stalwart_mailtest_user und '
              'souvera_central.stalwart_mailtest_password in der Nextcloud-config.php setzen.',
        'nl': 'Stalwart vereist SMTP-AUTH, terwijl Shield zonder AUTH verzendt (standaard sinds v3.8.0). '
              'Het Nextcloud-worker-IP staat blijkbaar niet op de trusted-relay-lijst van de '
              'Stalwart-submission-listener. Twee opties: (1) instrueer CloudManager om het '
              'Nextcloud-IP op de Stalwart-listener op poort %d als trusted source in te stellen, of '
              '(2) stel als tijdelijke oplossing souvera_central.stalwart_mailtest_user en '
              'souvera_central.stalwart_mailtest_password in de Nextcloud-config.php in.',
    },
    'and whether sender-rewriting rules block the sender': {
        'de': 'und ob Sender-Rewriting-Regeln den Absender blockieren',
        'nl': 'en of sender-rewriting-regels de afzender blokkeren',
    },
    'and whether the trusted-IP entry on the submission listener permits '
    'sending on behalf of this domain': {
        'de': 'und ob der Trusted-IP-Eintrag am Submission-Listener das Senden im Namen '
              'dieser Domain erlaubt',
        'nl': 'en of het trusted-IP-item op de submission-listener het verzenden namens '
              'dit domein toestaat',
    },
    'Stalwart rejects sender "%1$s". Please verify in Stalwart whether the domain "%2$s" '
    'is configured as a local sending domain %3$s.': {
        'de': 'Stalwart lehnt den Absender "%1$s" ab. Bitte in Stalwart prüfen, ob die Domain '
              '"%2$s" als lokale Sende-Domain angelegt ist %3$s.',
        'nl': 'Stalwart weigert afzender "%1$s". Controleer in Stalwart of het domein "%2$s" '
              'als lokaal verzenddomein is geconfigureerd %3$s.',
    },
    'Please verify in Stalwart whether authenticated users are allowed to relay '
    'externally (session/relay policy for the listener on port %d).': {
        'de': 'Bitte in Stalwart prüfen, ob authentifizierte Nutzer nach extern relayen dürfen '
              '(Session-/Relay-Policy für den Listener auf Port %d).',
        'nl': 'Controleer in Stalwart of geauthenticeerde gebruikers extern mogen relayen '
              '(session/relay-policy voor de listener op poort %d).',
    },
    'The Nextcloud worker IP is accepted for the session but not allowed to relay '
    'externally. Please verify in CloudManager that the trusted-IP entry on the '
    'Stalwart listener on port %d includes the relay grant for external recipients.': {
        'de': 'Die Nextcloud-Worker-IP ist zwar für die Session akzeptiert, darf aber nicht '
              'nach extern relayen. Bitte im CloudManager prüfen, dass der Trusted-IP-Eintrag '
              'am Stalwart-Listener auf Port %d die Relay-Freigabe für externe Empfänger enthält.',
        'nl': 'Het Nextcloud-worker-IP is wel geaccepteerd voor de sessie, maar mag niet extern '
              'relayen. Controleer in CloudManager of het trusted-IP-item op de Stalwart-listener '
              'op poort %d de relay-toestemming voor externe ontvangers bevat.',
    },
    'Stalwart rejects external recipient %s.': {
        'de': 'Stalwart lehnt den externen Empfänger %s ab.',
        'nl': 'Stalwart weigert externe ontvanger %s.',
    },
    'Stalwart rejected the message after DATA (content filter / spam policy). '
    'Please check the DATA reject reason in the Stalwart log.': {
        'de': 'Stalwart hat die Nachricht nach DATA abgelehnt (Inhaltsfilter/Spam-Policy). '
              'Bitte im Stalwart-Log den DATA-Reject-Grund prüfen.',
        'nl': 'Stalwart heeft het bericht na DATA geweigerd (contentfilter/spampolicy). '
              'Controleer de DATA-reject-reden in het Stalwart-log.',
    },
    'souvera_central.stalwart_mailtest_user ("%1$s") does not belong to workspace '
    'domain %2$s. The reputation test must be sent from the tested domain '
    '(SPF/DKIM/DMARC alignment) – please use a mailbox such as mailtest%3$s.': {
        'de': 'souvera_central.stalwart_mailtest_user ("%1$s") gehört nicht zur '
              'Workspace-Domain %2$s. Der Reputations-Test muss von der getesteten '
              'Domain gesendet werden (SPF/DKIM/DMARC-Alignment) – bitte ein Postfach '
              'wie mailtest%3$s verwenden.',
        'nl': 'souvera_central.stalwart_mailtest_user ("%1$s") hoort niet bij het '
              'workspace-domein %2$s. De reputatietest moet vanaf het geteste domein '
              'worden verzonden (SPF/DKIM/DMARC alignment) – gebruik een postvak '
              'zoals mailtest%3$s.',
    },
    'Stalwart relay is not configured. The reputation test must be sent through the '
    'customer\'s Stalwart server (so the test measures the correct IP, SPF, DKIM '
    'and DMARC). Please set `souvera_central.stalwart_api_url` (or '
    '`souvera_central.stalwart_smtp_host`) in the Nextcloud `config.php`.': {
        'de': 'Stalwart-Relay ist nicht konfiguriert. Der Reputations-Test muss über den '
              'Stalwart-Server des Kunden laufen (damit der Test die richtige IP, SPF, DKIM '
              'und DMARC misst). Bitte `souvera_central.stalwart_api_url` (oder '
              '`souvera_central.stalwart_smtp_host`) in der Nextcloud-`config.php` setzen.',
        'nl': 'Stalwart-relay is niet geconfigureerd. De reputatietest moet via de '
              'Stalwart-server van de klant lopen (zodat de test het juiste IP, SPF, DKIM '
              'en DMARC meet). Stel `souvera_central.stalwart_api_url` (of '
              '`souvera_central.stalwart_smtp_host`) in de Nextcloud-`config.php` in.',
    },

    # -------- MailTestService: run() / refreshResult() --------
    'Mail dispatch failed: %s': {
        'de': 'Mail-Versand fehlgeschlagen: %s',
        'nl': 'Mailverzending mislukt: %s',
    },
    'The test expired – the e-mail was not received within 1 hour.': {
        'de': 'Der Test ist abgelaufen – die E-Mail wurde nicht innerhalb von 1 Stunde empfangen.',
        'nl': 'De test is verlopen – de e-mail is niet binnen 1 uur ontvangen.',
    },
    'Timed out waiting for the test mail to be received.': {
        'de': 'Zeitüberschreitung beim Warten auf den Empfang der Test-Mail.',
        'nl': 'Time-out bij het wachten op de ontvangst van de test-mail.',
    },

    # -------- SmtpMailTestRelay --------
    'The PHP functions stream_socket_client/fsockopen are disabled on the Nextcloud host '
    '(php.ini disable_functions) – outbound SMTP connections are therefore impossible.': {
        'de': 'Die PHP-Funktionen stream_socket_client/fsockopen sind auf dem Nextcloud-Host '
              'deaktiviert (php.ini disable_functions) – ausgehende SMTP-Verbindungen sind damit '
              'nicht möglich.',
        'nl': 'De PHP-functies stream_socket_client/fsockopen zijn op de Nextcloud-host '
              'uitgeschakeld (php.ini disable_functions) – uitgaande SMTP-verbindingen zijn '
              'daardoor niet mogelijk.',
    },
    'Timeout after %ds without reply [packets are being dropped – check firewall/routing '
    'between Nextcloud host and Stalwart (DROP rule, Docker/K8s network)]': {
        'de': 'Timeout nach %ds ohne Antwort [Pakete werden verworfen – Firewall/Routing '
              'zwischen Nextcloud-Host und Stalwart prüfen (DROP-Regel, Docker-/K8s-Netzwerk)]',
        'nl': 'Time-out na %ds zonder reactie [pakketten worden gedropt – controleer firewall/routing '
              'tussen Nextcloud-host en Stalwart (DROP-regel, Docker-/K8s-netwerk)]',
    },
    '[Permission denied: the webserver process is not allowed to open SMTP ports – '
    'typically SELinux (on the Nextcloud host: setsebool -P httpd_can_sendmail on) '
    'or an AppArmor/container profile]': {
        'de': '[Permission denied: Der Webserver-Prozess darf keine SMTP-Ports öffnen – '
              'typisch SELinux (auf dem Nextcloud-Host: setsebool -P httpd_can_sendmail on) '
              'oder ein AppArmor-/Container-Profil]',
        'nl': '[Permission denied: het webserverproces mag geen SMTP-poorten openen – '
              'meestal SELinux (op de Nextcloud-host: setsebool -P httpd_can_sendmail on) '
              'of een AppArmor-/containerprofiel]',
    },
    '[Timeout: packets are being dropped – check firewall/routing between Nextcloud host '
    'and Stalwart]': {
        'de': '[Timeout: Pakete werden verworfen – Firewall/Routing zwischen Nextcloud-Host '
              'und Stalwart prüfen]',
        'nl': '[Time-out: pakketten worden gedropt – controleer firewall/routing tussen '
              'Nextcloud-host en Stalwart]',
    },
    '[Connection refused: no service is listening on that port on this host, or a firewall '
    'actively rejects the connection]': {
        'de': '[Connection refused: Auf diesem Host lauscht kein Dienst auf dem Port oder '
              'eine Firewall lehnt aktiv ab]',
        'nl': '[Connection refused: op deze host luistert geen dienst op die poort of '
              'een firewall wijst de verbinding actief af]',
    },
    '[No route to host: the network route from the Nextcloud host is missing]': {
        'de': '[No route to host: Netzwerk-Route vom Nextcloud-Host fehlt]',
        'nl': '[No route to host: de netwerkroute vanaf de Nextcloud-host ontbreekt]',
    },

    # -------- IncidentService: titles --------
    'SPF record missing or invalid': {
        'de': 'SPF-Record fehlt oder ist fehlerhaft',
        'nl': 'SPF-record ontbreekt of is ongeldig',
    },
    'DMARC policy missing or too weak': {
        'de': 'DMARC-Policy fehlt oder ist zu schwach',
        'nl': 'DMARC-beleid ontbreekt of is te zwak',
    },
    'MTA-STS policy not reachable': {
        'de': 'MTA-STS-Policy nicht abrufbar',
        'nl': 'MTA-STS-beleid niet bereikbaar',
    },
    'TLS-RPT not configured': {
        'de': 'TLS-RPT nicht konfiguriert',
        'nl': 'TLS-RPT niet geconfigureerd',
    },
    'BIMI configuration incomplete': {
        'de': 'BIMI-Konfiguration unvollständig',
        'nl': 'BIMI-configuratie onvolledig',
    },
    'PTR / reverse-DNS record missing': {
        'de': 'PTR-/Reverse-DNS-Eintrag fehlt',
        'nl': 'PTR-/reverse-DNS-record ontbreekt',
    },
    'SMTP transport encryption (STARTTLS) missing': {
        'de': 'SMTP-Transportverschlüsselung (STARTTLS) fehlt',
        'nl': 'SMTP-transportversleuteling (STARTTLS) ontbreekt',
    },
    'DKIM signature failed': {
        'de': 'DKIM-Signatur fehlgeschlagen',
        'nl': 'DKIM-handtekening mislukt',
    },
    'SPF alignment failed': {
        'de': 'SPF-Alignment fehlgeschlagen',
        'nl': 'SPF-alignment mislukt',
    },
    'DKIM alignment failed': {
        'de': 'DKIM-Alignment fehlgeschlagen',
        'nl': 'DKIM-alignment mislukt',
    },
    'One-Click-Unsubscribe incomplete': {
        'de': 'One-Click-Unsubscribe unvollständig',
        'nl': 'One-Click-Unsubscribe onvolledig',
    },
    'Outbound IP is on blacklists': {
        'de': 'Ausgehende IP steht auf Blacklists',
        'nl': 'Uitgaand IP staat op blacklists',
    },
    'Domain is on blacklists': {
        'de': 'Domain steht auf Blacklists',
        'nl': 'Domein staat op blacklists',
    },
    'Check failed: %s': {
        'de': 'Check fehlgeschlagen: %s',
        'nl': 'Check mislukt: %s',
    },

    # -------- IncidentService: recommendations --------
    'Publish an SPF TXT record (v=spf1 … -all) in the domain DNS and include every '
    'legitimate sending IP.': {
        'de': 'SPF-TXT-Record (v=spf1 … -all) im DNS der Domain veröffentlichen und alle '
              'legitimen Versand-IPs aufnehmen.',
        'nl': 'Publiceer een SPF-TXT-record (v=spf1 … -all) in het domein-DNS en neem elk '
              'legitiem verzendend IP op.',
    },
    'Publish a DMARC record at _dmarc.<domain> with p=quarantine or p=reject and a rua= '
    'address.': {
        'de': 'DMARC-Record unter _dmarc.<domain> mit p=quarantine oder p=reject und '
              'rua=-Adresse veröffentlichen.',
        'nl': 'Publiceer een DMARC-record op _dmarc.<domein> met p=quarantine of p=reject '
              'en een rua=-adres.',
    },
    'Serve the policy file at https://mta-sts.<domain>/.well-known/mta-sts.txt (check '
    'the webserver and certificate).': {
        'de': 'Die Policy-Datei unter https://mta-sts.<domain>/.well-known/mta-sts.txt '
              'bereitstellen (Webserver + Zertifikat prüfen).',
        'nl': 'Serveer het policy-bestand op https://mta-sts.<domein>/.well-known/mta-sts.txt '
              '(controleer webserver en certificaat).',
    },
    'Publish the TXT record _smtp._tls.<domain> with v=TLSRPTv1; rua=mailto:….': {
        'de': 'TXT-Record _smtp._tls.<domain> mit v=TLSRPTv1; rua=mailto:… veröffentlichen.',
        'nl': 'Publiceer het TXT-record _smtp._tls.<domein> met v=TLSRPTv1; rua=mailto:….',
    },
    'For BIMI, DMARC must be p=quarantine or p=reject; then verify the SVG logo and '
    'a VMC certificate if needed.': {
        'de': 'Für BIMI muss DMARC auf p=quarantine oder p=reject stehen; anschließend '
              'SVG-Logo und ggf. VMC-Zertifikat prüfen.',
        'nl': 'Voor BIMI moet DMARC op p=quarantine of p=reject staan; controleer daarna '
              'het SVG-logo en eventueel een VMC-certificaat.',
    },
    'Ask the IP owner (hoster) to set a PTR record that points at the HELO hostname '
    '(Forward-Confirmed rDNS).': {
        'de': 'Beim Hoster der IP einen PTR-Eintrag setzen, der auf den HELO-Hostnamen '
              'zeigt (Forward-Confirmed rDNS).',
        'nl': 'Vraag de IP-eigenaar (hoster) een PTR-record in te stellen dat naar de '
              'HELO-hostnaam wijst (Forward-Confirmed rDNS).',
    },
    'Enable STARTTLS on the mail server and install a valid certificate.': {
        'de': 'STARTTLS auf dem Mailserver aktivieren und ein gültiges Zertifikat hinterlegen.',
        'nl': 'Activeer STARTTLS op de mailserver en installeer een geldig certificaat.',
    },
    'Enable DKIM signing in Stalwart and publish the public key as a TXT record '
    '(<selector>._domainkey.<domain>).': {
        'de': 'DKIM-Signierung in Stalwart aktivieren und den Public Key als TXT-Record '
              '(<selector>._domainkey.<domain>) veröffentlichen.',
        'nl': 'Activeer DKIM-ondertekening in Stalwart en publiceer de publieke sleutel als '
              'TXT-record (<selector>._domainkey.<domein>).',
    },
    'MAIL FROM (envelope) must use the customer domain; the domain SPF record must '
    'authorise the sending IP.': {
        'de': 'MAIL FROM (Envelope) muss die Kunden-Domain verwenden; SPF-Record der '
              'Domain muss die Versand-IP autorisieren.',
        'nl': 'MAIL FROM (envelope) moet het klantdomein gebruiken; het SPF-record van het '
              'domein moet het verzendende IP autoriseren.',
    },
    'The DKIM signature must be d=<customer-domain>, not a third-party domain.': {
        'de': 'DKIM-Signatur muss mit d=<kunden-domain> signieren, nicht mit einer fremden Domain.',
        'nl': 'De DKIM-handtekening moet d=<klantdomein> zijn, niet een domein van derden.',
    },
    'Add List-Unsubscribe and List-Unsubscribe-Post (RFC 8058) headers to bulk / '
    'newsletter mail.': {
        'de': 'List-Unsubscribe und List-Unsubscribe-Post (RFC 8058) in Massen-/Newsletter-'
              'Mails ergänzen.',
        'nl': 'Voeg List-Unsubscribe en List-Unsubscribe-Post (RFC 8058) headers toe aan '
              'bulk-/nieuwsbrief-mail.',
    },
    'Request delisting at the affected blacklists and fix the root cause '
    '(spam sending, open relay).': {
        'de': 'Delisting bei den betroffenen Blacklists beantragen und die Ursache '
              '(Spam-Versand, offenes Relay) beheben.',
        'nl': 'Vraag delisting aan bij de betreffende blacklists en verhelp de oorzaak '
              '(spamverzending, open relay).',
    },
    'Request domain delisting and investigate why the domain was listed '
    '(compromised accounts, spam content).': {
        'de': 'Delisting für die Domain beantragen und prüfen, wodurch die Listung '
              'ausgelöst wurde (kompromittierte Konten, Spam-Inhalte).',
        'nl': 'Vraag delisting voor het domein aan en onderzoek waarom het domein is '
              'gelijst (gecompromitteerde accounts, spamcontent).',
    },
    'Review the details in the check area of the reputation page.': {
        'de': 'Details im Check-Bereich der Reputationsseite prüfen.',
        'nl': 'Bekijk de details in het check-gebied van de reputatiepagina.',
    },

    # -------- IncidentService: DMARC pass rate --------
    'Low DMARC pass rate (%d %%)': {
        'de': 'Niedrige DMARC-Pass-Rate (%d %%)',
        'nl': 'Lage DMARC-pass rate (%d %%)',
    },
    'Only %1$d %% of %2$d reported messages pass DMARC (DKIM %3$s, SPF %4$s). '
    'Recipients like Google and Microsoft increasingly classify unauthenticated '
    'mail as spam or reject it outright.': {
        'de': 'Nur %1$d %% von %2$d gemeldeten Nachrichten bestehen die DMARC-Prüfung '
              '(DKIM %3$s, SPF %4$s). Empfänger wie Google und Microsoft stufen '
              'unauthentifizierte Mails zunehmend als Spam ein oder weisen sie ab.',
        'nl': 'Slechts %1$d %% van %2$d gerapporteerde berichten slaagt voor DMARC '
              '(DKIM %3$s, SPF %4$s). Ontvangers zoals Google en Microsoft classificeren '
              'niet-geauthenticeerde mail steeds vaker als spam of weigeren die.',
    },
    'Verify DKIM signing and SPF authorisation for every sending path. The source '
    'classification shows which senders fail the checks.': {
        'de': 'DKIM-Signierung und SPF-Autorisierung für alle Versandwege prüfen. Die '
              'Quellen-Klassifizierung zeigt, welche Absender die Prüfungen nicht bestehen.',
        'nl': 'Controleer DKIM-ondertekening en SPF-autorisatie voor elk verzendpad. De '
              'bronclassificatie toont welke afzenders de checks niet halen.',
    },

    # -------- IncidentService: anomalies --------
    'Unusual sending spike on %s': {
        'de': 'Ungewöhnlicher Versand-Anstieg am %s',
        'nl': 'Ongebruikelijke verzendpiek op %s',
    },
    'On %1$s %2$s messages were reported – significantly above the usual daily '
    'volume of ~%3$s. A sudden volume spike may indicate a compromised account '
    'or an abused script.': {
        'de': 'Am %1$s wurden %2$s Nachrichten gemeldet – deutlich über dem üblichen '
              'Tagesvolumen von ~%3$s. Ein plötzlicher Volumen-Anstieg kann auf ein '
              'kompromittiertes Konto oder ein missbrauchtes Script hindeuten.',
        'nl': 'Op %1$s werden %2$s berichten gerapporteerd – duidelijk boven het gebruikelijke '
              'dagvolume van ~%3$s. Een plotselinge volumepiek kan wijzen op een '
              'gecompromitteerd account of een misbruikt script.',
    },
    'Review the sending logs for that day, identify unusual sender accounts, '
    'reset affected passwords and revoke app-passwords where needed.': {
        'de': 'Versand-Logs des betreffenden Tages prüfen, ungewöhnliche Absender-Konten '
              'identifizieren, betroffene Passwörter zurücksetzen und ggf. App-Passwörter '
              'widerrufen.',
        'nl': 'Bekijk de verzendlogs van die dag, identificeer ongebruikelijke '
              'afzendersaccounts, reset betrokken wachtwoorden en trek waar nodig '
              'app-wachtwoorden in.',
    },
    'Potentially abusive sending source: %s': {
        'de': 'Potenziell missbräuchliche Versandquelle: %s',
        'nl': 'Mogelijk misbruikte verzendbron: %s',
    },
    'The source "%1$s" sent %2$d messages on behalf of domain %3$s without '
    'passing SPF or DKIM (%4$d %% pass rate). This may be spoofing/phishing '
    'in the name of the domain – or a legitimate service that is not yet '
    'configured for SPF/DKIM.': {
        'de': 'Die Quelle "%1$s" hat %2$d Nachrichten im Namen der Domain %3$s versendet, '
              'ohne SPF- oder DKIM-Prüfung zu bestehen (%4$d %% Pass-Rate). Das kann '
              'Spoofing/Phishing im Namen der Domain sein – oder ein legitimer Dienst, '
              'der noch nicht für SPF/DKIM konfiguriert wurde.',
        'nl': 'De bron "%1$s" heeft %2$d berichten namens domein %3$s verzonden zonder '
              'SPF of DKIM te halen (%4$d %% pass rate). Dit kan spoofing/phishing namens '
              'het domein zijn – of een legitieme dienst die nog niet voor SPF/DKIM is '
              'geconfigureerd.',
    },
    'Check whether the source is legitimate. If legitimate → add SPF include / '
    'DKIM key. If not → tighten DMARC policy to p=reject so recipients reject '
    'these messages.': {
        'de': 'Prüfen, ob die Quelle legitim ist. Legitim → SPF-Include/DKIM-Key ergänzen. '
              'Nicht legitim → DMARC-Policy auf p=reject verschärfen, damit Empfänger '
              'diese Mails abweisen.',
        'nl': 'Controleer of de bron legitiem is. Legitiem → voeg SPF-include / DKIM-sleutel '
              'toe. Niet legitiem → verscherp het DMARC-beleid naar p=reject zodat ontvangers '
              'deze berichten weigeren.',
    },

    # -------- IncidentService: mail-test failures --------
    'Unknown error while dispatching the test mail.': {
        'de': 'Unbekannter Fehler beim Versand der Test-Mail.',
        'nl': 'Onbekende fout bij het verzenden van de test-mail.',
    },
    'Last reputation mail-test failed': {
        'de': 'Letzter Reputations-Mail-Test fehlgeschlagen',
        'nl': 'Laatste reputatie-mailtest mislukt',
    },
    'The diagnosis above names the failed SMTP stage and the configuration item to check. '
    'After fixing it, start a new mail test.': {
        'de': 'Die Diagnose oben nennt die fehlgeschlagene SMTP-Stufe und den zu prüfenden '
              'Konfigurationspunkt. Nach der Korrektur einen neuen Mail-Test starten.',
        'nl': 'De diagnose hierboven noemt de mislukte SMTP-fase en het te controleren '
              'configuratieonderdeel. Start na de correctie een nieuwe mailtest.',
    },
    'Weak mail-test score (%s/10)': {
        'de': 'Schwacher Mail-Test-Score (%s/10)',
        'nl': 'Zwakke mailtest-score (%s/10)',
    },
    'The most recent completed reputation test scored only %1$s of 10 points '
    '(SPF: %2$s, DKIM: %3$s, DMARC: %4$s).': {
        'de': 'Der letzte abgeschlossene Reputations-Test erreichte nur %1$s von 10 Punkten '
              '(SPF: %2$s, DKIM: %3$s, DMARC: %4$s).',
        'nl': 'De laatste voltooide reputatietest behaalde slechts %1$s van de 10 punten '
              '(SPF: %2$s, DKIM: %3$s, DMARC: %4$s).',
    },
    'Open the mail-test detail analysis and fix the criticised points '
    '(authentication, spam score, server configuration) one by one.': {
        'de': 'Die Detail-Analyse des Mail-Tests öffnen und die bemängelten Punkte '
              '(Authentifizierung, Spam-Score, Server-Konfiguration) nacheinander beheben.',
        'nl': 'Open de detailanalyse van de mailtest en verhelp de aangegeven punten '
              '(authenticatie, spamscore, serverconfiguratie) één voor één.',
    },

    # -------- IncidentService: measures log messages --------
    'Condition detected again.': {
        'de': 'Zustand erneut erkannt.',
        'nl': 'Toestand opnieuw gedetecteerd.',
    },
    'Automatically detected by the reputation analysis.': {
        'de': 'Automatisch durch die Reputations-Analyse erkannt.',
        'nl': 'Automatisch gedetecteerd door de reputatieanalyse.',
    },
    'Condition was no longer detected in the latest analysis.': {
        'de': 'Zustand wurde bei der letzten Analyse nicht mehr erkannt.',
        'nl': 'Toestand werd bij de laatste analyse niet meer gedetecteerd.',
    },
    'Manually marked as resolved.': {
        'de': 'Manuell als behoben markiert.',
        'nl': 'Handmatig als opgelost gemarkeerd.',
    },

    # -------- IncidentService: describeCheckFailure --------
    'The outbound IP %1$s is listed on %2$d of %3$d checked blacklists: %4$s. '
    'Listed senders are rejected or marked as spam by many recipients.': {
        'de': 'Die ausgehende IP %1$s ist auf %2$d von %3$d geprüften Blacklists gelistet: '
              '%4$s. Gelistete Absender werden von vielen Empfängern abgewiesen oder als '
              'Spam einsortiert.',
        'nl': 'Het uitgaande IP %1$s staat op %2$d van %3$d gecontroleerde blacklists: '
              '%4$s. Gelijste afzenders worden door veel ontvangers geweigerd of als '
              'spam gemarkeerd.',
    },
    'The domain %1$s is listed on %2$d of %3$d checked blacklists: %4$s. '
    'Listed senders are rejected or marked as spam by many recipients.': {
        'de': 'Die Domain %1$s ist auf %2$d von %3$d geprüften Blacklists gelistet: %4$s. '
              'Gelistete Absender werden von vielen Empfängern abgewiesen oder als Spam '
              'einsortiert.',
        'nl': 'Het domein %1$s staat op %2$d van %3$d gecontroleerde blacklists: %4$s. '
              'Gelijste afzenders worden door veel ontvangers geweigerd of als spam '
              'gemarkeerd.',
    },
    'No valid SPF record (v=spf1 …) was found for the domain. Without SPF, receivers '
    'cannot verify which servers are allowed to send in the name of the domain.': {
        'de': 'Für die Domain wurde kein gültiger SPF-Record (v=spf1 …) gefunden. Ohne SPF '
              'können Empfänger nicht prüfen, welche Server im Namen der Domain senden dürfen.',
        'nl': 'Voor het domein is geen geldig SPF-record (v=spf1 …) gevonden. Zonder SPF '
              'kunnen ontvangers niet verifiëren welke servers namens het domein mogen '
              'verzenden.',
    },
    'No DMARC record (_dmarc.<domain>) was found for the domain. Without DMARC anyone '
    'can spoof the domain, and there are no reports about abuse.': {
        'de': 'Für die Domain wurde kein DMARC-Record (_dmarc.<domain>) gefunden. Ohne '
              'DMARC kann jeder Absender die Domain fälschen, und es gibt keine Reports '
              'über Missbrauch.',
        'nl': 'Voor het domein is geen DMARC-record (_dmarc.<domein>) gevonden. Zonder '
              'DMARC kan iedereen het domein spoofen en er zijn geen rapporten over '
              'misbruik.',
    },
    'The MTA-STS DNS record exists but the policy file is not reachable over HTTPS. '
    'Sending servers cannot apply the TLS policy as a result.': {
        'de': 'Der MTA-STS-DNS-Eintrag existiert, aber die Policy-Datei ist nicht über HTTPS '
              'abrufbar. Sendende Server können die TLS-Policy dadurch nicht anwenden.',
        'nl': 'Het MTA-STS-DNS-record bestaat, maar het policy-bestand is niet via HTTPS '
              'bereikbaar. Verzendende servers kunnen het TLS-beleid daardoor niet toepassen.',
    },
    'No PTR/reverse-DNS record exists for the outbound IP %s. Many receivers (e.g. GMX, '
    'T-Online) reject mail from IPs without PTR.': {
        'de': 'Für die ausgehende IP %s existiert kein PTR-/Reverse-DNS-Eintrag. Viele '
              'Empfänger (u. a. GMX, T-Online) weisen Mails von IPs ohne PTR ab.',
        'nl': 'Voor het uitgaande IP %s bestaat geen PTR-/reverse-DNS-record. Veel '
              'ontvangers (o.a. GMX, T-Online) weigeren mail van IPs zonder PTR.',
    },
    'The mail server does not offer STARTTLS – mail would be transferred unencrypted '
    'and rejected by recipients that require TLS.': {
        'de': 'Der Mailserver bietet kein STARTTLS an – Mails würden unverschlüsselt '
              'übertragen und von Empfängern mit TLS-Pflicht abgewiesen.',
        'nl': 'De mailserver biedt geen STARTTLS aan – mail zou onversleuteld worden '
              'overgedragen en door ontvangers met TLS-verplichting worden geweigerd.',
    },
    'The DKIM signature of the latest mail test was invalid (result: %s). Invalidly '
    'signed mail loses DMARC protection and reputation.': {
        'de': 'Die DKIM-Signatur des letzten Mail-Tests war ungültig (Ergebnis: %s). '
              'Ungültig signierte Mails verlieren DMARC-Schutz und Reputation.',
        'nl': 'De DKIM-handtekening van de laatste mailtest was ongeldig (resultaat: %s). '
              'Ongeldig ondertekende mail verliest DMARC-bescherming en reputatie.',
    },
    'SPF does not pass or is not aligned with the customer domain (SPF domain: %s). '
    'Only an aligned SPF pass counts for DMARC.': {
        'de': 'SPF besteht nicht bzw. ist nicht auf die Kunden-Domain ausgerichtet '
              '(SPF-Domain: %s). Für DMARC zählt nur ein alignter SPF-Pass.',
        'nl': 'SPF slaagt niet of is niet uitgelijnd met het klantdomein (SPF-domein: %s). '
              'Voor DMARC telt alleen een uitgelijnde SPF-pass.',
    },
    'DKIM does not pass or signs with a third-party domain (%s). Only an aligned DKIM '
    'signature counts for DMARC.': {
        'de': 'DKIM besteht nicht bzw. signiert mit einer fremden Domain (%s). Für DMARC '
              'zählt nur eine alignte DKIM-Signatur.',
        'nl': 'DKIM slaagt niet of ondertekent met een domein van derden (%s). Voor DMARC '
              'telt alleen een uitgelijnde DKIM-handtekening.',
    },
    'The check "%1$s" failed. Observed values: %2$s': {
        'de': 'Der Check "%1$s" ist fehlgeschlagen. Beobachtete Werte: %2$s',
        'nl': 'De check "%1$s" is mislukt. Waargenomen waarden: %2$s',
    },
}


def load(name: str) -> dict:
    return json.loads((L10N / name).read_text(encoding='utf-8'))

def save(name: str, data: dict) -> None:
    (L10N / name).write_text(
        json.dumps(data, ensure_ascii=False, indent=4, sort_keys=False) + '\n',
        encoding='utf-8',
    )

def save_js(name: str, translations: dict, plurals: str) -> None:
    body = json.dumps(translations, ensure_ascii=False, indent=4, sort_keys=False)
    (L10N / name).write_text(
        f'OC.L10N.register(\n    "souvera_shield",\n    {body},\n    "{plurals}");\n',
        encoding='utf-8',
    )

def merge_locale(lang: str, key_of_lang: str, plurals: str) -> None:
    data = load(f'{lang}.json')
    trans = data.setdefault('translations', {})
    added = 0
    for en, entry in TRANSLATIONS.items():
        target = entry[key_of_lang] if key_of_lang in entry else en
        if en not in trans:
            trans[en] = target
            added += 1
        # Overwrite any previous placeholder-mismatch we might have accumulated.
        else:
            if key_of_lang == 'en' or trans[en] == en:
                trans[en] = target
    save(f'{lang}.json', data)
    save_js(f'{lang}.js', trans, plurals)
    print(f'  {lang}: +{added} new keys, total={len(trans)}')

print('Merging PHP l10n strings (v3.9.0) into locale files...')
# EN (source language = key itself)
data_en = load('en_GB.json')
trans_en = data_en.setdefault('translations', {})
added_en = 0
for en in TRANSLATIONS:
    if en not in trans_en:
        trans_en[en] = en
        added_en += 1
save('en_GB.json', data_en)
save_js('en_GB.js', trans_en, 'nplurals=2; plural=(n != 1);')
print(f'  en_GB: +{added_en} new keys, total={len(trans_en)}')

merge_locale('de',    'de', 'nplurals=2; plural=(n != 1);')
merge_locale('de_DE', 'de', 'nplurals=2; plural=(n != 1);')
merge_locale('nl',    'nl', 'nplurals=2; plural=(n != 1);')

print(f'\nDone. {len(TRANSLATIONS)} source strings distributed.')
