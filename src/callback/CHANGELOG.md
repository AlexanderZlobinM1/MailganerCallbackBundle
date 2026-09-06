# Changelog

## [1.3.0] - 2026-09-07

- Build this standalone package from a single shared source tree with the API variant. Callback, DNC, settings and form logic have one source; no synchronization script or runtime dependency on the API plugin is required.
- Keep bundle identity, existing settings and Mautic 5/6/7 compatibility.

## [1.2.1] - 2026-09-06

- Make the Sales Snap branding in the integration footer an active link to `https://sales-snap.ru`.

## [1.2.0] - 2026-09-06

- Share the Mailganer integration identity and common setting fields between API and Callback variants. Native installation and migration adopt legacy callback settings without changing explicit publication state.
- Retain API sending controls when the callback-only form is saved, and preserve false boolean values during encryption.
- Dim and lock dependent settings while the general switch is off; retain their submitted values. Verify an already-created sender stops before any new API request after disabling.

## [1.1.3] - 2026-09-06

- Serialize callback DNC updates per contact, ignore repeated feedback and preserve unsubscribe state and original email attribution.

- Preserve SMTP callback attribution, stop interpreting provider IDs as Mautic IDs, and return retryable errors for failed database writes. Exclude the full bundle from lite service discovery.
- Audit and regression coverage against managed SES 1.0.38.x; see `LINEAGE_AUDIT.md`.

## 1.1.2 — 2026-09-06

- Support Mautic 7.2 while retaining the declared older Mautic versions.
- Use a plugin-scoped EncryptionHelper service alias; keep legacy argument parsing and the global core container unchanged.
- Add a fresh-kernel regression check that instantiates integration services and resolves form types.
- Preserve the Mautic 5 session constructor argument and correctly recognize two-digit patch versions such as 5.2.10.


## 1.1.1 - 2026-08-30

- Added standalone PHPUnit development dependencies and a Composer test script.
- Corrected the disabled-plugin regression test to exercise the public webhook
  handler on current Mautic versions.
- Added the canonical `ru` translation catalog while retaining legacy `ru_RU`.

## 1.1 - 2026-07-12

- Changed incoming webhook debug logging to use the standard Mautic logger only.
  The plugin no longer writes a separate `Mailganer.log` file with
  `file_put_contents`, matching the Sendgrid callback plugin behavior and
  avoiding installation-layout-specific log paths.

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
