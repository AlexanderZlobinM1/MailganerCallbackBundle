# Changelog

## 1.0.4 - 2026-07-12

- Fixed dedicated callback debug logging on Composer/docroot Mautic installs.
  `Mailganer.log` now resolves the real Mautic project root by locating
  `bin/console` and `var/`, so logs are written to the project-level
  `var/logs` directory instead of a non-existent `docroot/var/logs` path.

## 1.0.3 - 2026-07-06

- Added Sales Snap footer link to `sales-snap.ru` in the integration modal.

## 1.0.2 - 2026-02-26

- Added dedicated callback debug file logging to `var/logs/Mailganer.log` (JSON lines).

## 1.0.1 - 2026-02-26

- Added optional debug logging switch for incoming webhooks in integration settings.
- Added detailed inbound callback logs (IP, headers, raw body, processing summary) for provider-side diagnostics.

## 1.0.0 - 2026-02-25

- Initial Mailganer (Samotpravil) callback plugin for Mautic 5/6/7.
- Added webhook processing for `failed`, `fbl`, `unsubscribe` statuses.
- Added support for both webhook payload envelopes: `messages` and `xml_messages`.
- Added integration modal switches for each supported status.
- Added SMTP transport host guard for `api.samotpravil.ru` and `smtp.mailganer.com`.
