# Changelog

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
