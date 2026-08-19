# Changelog

## [4.0.55] — 2026-08

### Fixed

- The daily report send time is now evaluated in the tenant's configured
  timezone (Nextcloud `default_timezone`) instead of the server's PHP
  timezone — reports arrive at the locally configured hour.
- The job now ticks every 5 minutes, so the daily mail goes out within
  minutes of the configured hour (instead of up to an hour later).

### Changed

- The report mail is now a styled multipart message (HTML design with
  per-mailbox cards and quarantine tables, plus a plain-text fallback).
  All sender/subject data is HTML-escaped.

## [4.0.54] — 2026-08

### Changed

- The daily spam report now covers ALL identities of a user: primary
  address, aliases and shared mailboxes are reported in separate sections
  of one mail (only identities with entries are listed).

## [4.0.53] — 2026-08

### Added

- Daily spam report: every user with the Central "daily summary" setting
  receives a mail (spam-report@<domain> via the workspace Stalwart relay)
  listing the spam, virus and attachment quarantine entries of the last 24
  hours, with a link to the Shield quarantine view. Sent once per day at
  the configured hour.
- PMG built-in spam report is automatically disabled (reportstyle=none)
  when enabled in Central settings — Souvera replaces it with its own.

## [4.0.52] — 2026-08

### Fixed

- Input bindings for @nextcloud/vue 9.9: search fields and the add-entry
  dialog in whitelist/blacklist (and the quarantine search) used the
  removed `update:value` event — the fields never received input, so
  search did nothing and adding silently failed. All inputs now use
  v-model. Same fix for the settings toggles (`update:checked` → v-model).

## [4.0.51] — 2026-08

### Fixed

- Whitelist/blacklist: backend errors are now surfaced properly — the UI
  shows an inline error with retry instead of a misleading empty list,
  partial mailbox failures are listed as warnings, and the add dialog
  stays open on failure so the error message remains visible.

## [4.0.50] — 2026-08

### Changed

- Whitelist/blacklist now cover ALL identities of a user (primary, aliases,
  shared mailboxes): entries are merged across mailboxes and add/remove
  operations apply to every mailbox.
- All data tables are responsive: on narrow screens they render as stacked
  cards with visible field labels instead of a squeezed table.

### Fixed

- Whitelist/blacklist list/add/remove no longer depend on the raw NC e-mail
  address alone; identity discovery is used with a safe fallback.

## [4.0.49] — 2026-08

Repository moved to the Host-On organization. This release repoints the
self-update sources and neutralizes internal references for the open-source
publish.

## [4.0.48] — 2026-08

### Fixed

- Pager buttons no longer emit NcButton warnings.
