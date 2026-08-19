# Souvera Shield – Nextcloud App

![License](https://img.shields.io/badge/license-AGPL--3.0-blue) ![Nextcloud](https://img.shields.io/badge/Nextcloud-32%E2%80%9334-0082c9) ![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)

Souvera Shield is the Nextcloud user interface for **Proxmox Mail Gateway (PMG)**.
It lets every user manage their **own** spam-, file- and virus quarantine as well
as a personal **whitelist / blacklist** – without ever logging into PMG directly.

> ⚠️ **Part of Souvera Shield.** The app is designed to be operated together
> with the rest of the Souvera Shield architecture (PMG, mail relay, …).
> It also works stand-alone on top of any PMG installation.

---

## ✨ Features

| Feature                 | Description                                                          |
| ----------------------- | -------------------------------------------------------------------- |
| Overview dashboard      | Counts for every queue + the most recent quarantine messages.        |
| Spam quarantine         | Preview, release or delete.                                          |
| File quarantine         | Attachments held by the content filter. *(admin can disable)*        |
| Virus quarantine        | Mails with infected attachments. *(admin can disable)*               |
| Personal whitelist      | Senders / domains that bypass the spam filter.                       |
| Personal blacklist      | Senders / domains that are always treated as spam.                   |
| Admin settings          | Toggle which queues users can access.                                |
| Native NC look & feel   | Uses Nextcloud's `app-navigation` / `app-content` layout & theming.  |

---

## 🛠️ Configuration

The app reads its PMG credentials from either `config.php` (system) or the app
config. System config wins, so secrets can live outside the database:

```bash
# Required
sudo -u www-data php occ config:app:set souvera_shield pmg_domain          --value="https://pmg.example.com:8006"
sudo -u www-data php occ config:app:set souvera_shield pmg_username        --value="shield@pmg"
sudo -u www-data php occ config:app:set souvera_shield pmg_password        --value="…"

# Domains users may manage (comma separated)
sudo -u www-data php occ config:app:set souvera_shield pmg_allowed_domains --value="example.com,souvera.eu"

# Optional – disable TLS verification (dev only!)
sudo -u www-data php occ config:app:set souvera_shield pmg_allow_insecure  --value="false"

# Optional – disable the file / virus quarantine for end users
sudo -u www-data php occ config:app:set souvera_shield allow_file_quarantine  --value="true"
sudo -u www-data php occ config:app:set souvera_shield allow_virus_quarantine --value="true"
```

---

## 🧱 Architecture

```
appinfo/
  info.xml          ← App manifest + declarative <navigations>
  routes.php        ← Route table (mirrored by PHP attributes)
lib/
  AppInfo/Application.php   ← IBootstrap, DI registration
  Controller/PageController.php  ← Renders templates/main.php
  Controller/ApiController.php   ← REST endpoints
  Service/PMGClient.php          ← Talks to PMG via IClientService
  Service/PMGException.php       ← Typed exception with HTTP status
templates/
  main.php          ← Single template, switches on $page
css/style.css       ← Uses Nextcloud design tokens only
js/app.js           ← Vanilla JS, no build pipeline required
tests/
  unit/             ← PHPUnit tests with mocked HTTP client
```

The frontend uses Nextcloud's standard layout elements (`#app-navigation` and
`#app-content`), so the app integrates seamlessly with every NC theme – light,
dark, high contrast.

---

## 🧪 Tests

```bash
composer install
composer test
# or directly:
vendor/bin/phpunit -c tests/phpunit.xml
```

The unit tests mock `IClientService`/`IClient` and the Nextcloud `IConfig`, so
no Nextcloud or PMG instance is required to run them.

---

## 🇩🇪 Hinweis

Diese App ist Bestandteil von **Souvera Shield**. Sie greift auf Proxmox Mail
Gateway zu und lässt Spam-/Antivirus-Quarantäne direkt in Nextcloud verwalten.
Layout & Theming folgen Nextcloud 32–34+ – Light-, Dark- und High-Contrast-
Theme werden vollständig respektiert.

Weitere Informationen: <https://souvera.eu>
