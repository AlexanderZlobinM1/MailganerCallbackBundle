# Changelog

## [1.6.0] - 2026-09-07

- Build this standalone package from a single shared source tree with the Callback variant. Callback, DNC, settings and form logic have one source; no synchronization script or runtime dependency on the Callback plugin is required.
- Preserve concurrent API delivery, package sending, adaptive/manual speed and existing settings.

## [1.5.1] - 2026-09-06

- Make the Sales Snap branding in the integration footer an active link to `https://sales-snap.ru`.

## [1.5.0] - 2026-09-06

- Share the Mailganer integration identity and common setting fields between API and Callback variants. Native installation and migration adopt legacy callback settings without changing explicit publication state.
- Retain API sending controls when the callback-only form is saved, and preserve false boolean values during encryption.
- Dim and lock dependent settings while the general switch is off; retain their submitted values. Verify an already-created sender stops before any new API request after disabling.

## [1.4.0] - 2026-09-06

- Send personal Mautic messages concurrently by default, retaining text, attachments and recipient-specific headers. JSON packages remain an explicit delivery mode with compatible-message fallback.
- Add live UI/CLI speed and concurrency controls: blank adaptive mode, numeric manual ceiling, zero pause; share rate state and provider cooldown across installation workers.
- Start adaptive sending at 10 emails/second and adjust on successful/throttled responses; never pretend this is a provider-reported quota.
- Drain every started request after partial failure and persist individual acceptance receipts before retries.
- Add English, Russian and Serbian settings and regression coverage across Mautic 5/6/7.

## [1.3.0] - 2026-09-06

- Serialize callback DNC updates per contact, ignore repeated feedback and preserve unsubscribe state and original email attribution.

- Add current single-send API, native Mautic token batches using JSON packages, persistent submission receipts and provider management/diagnostic commands. Preserve raw content, ordinary attachments, Reply-To and per-recipient attribution; surface unsupported MIME and API/storage failures.
- Audit and regression coverage against managed SES 1.0.38.x; see `LINEAGE_AUDIT.md`.

## 1.2.1 — 2026-09-06

- Support Mautic 7.2 while retaining the declared older Mautic versions.
- Use a plugin-scoped EncryptionHelper service alias; keep legacy argument parsing and the global core container unchanged.
- Add a fresh-kernel regression check that instantiates integration services and resolves form types.
- Preserve the Mautic 5 session constructor argument and correctly recognize two-digit patch versions such as 5.2.10.
- Exclude factory-created API transports from service autodiscovery.


## 1.2.0 - 2026-08-30

- Added a publication guard to the API transport factory: an unpublished
  integration neither advertises nor creates the Mailganer transport.
- Switched callback diagnostics to the standard Mautic logger only.
- Added canonical `ru` translations and standalone PHPUnit dependencies.
- Aligned `email_from` formatting and API payload assertions with the official
  Mailganer v2 send contract.

## 1.1.0 - 2026-03-04

- Added Mailganer API sending transport (`mailganer+api://` DSN).
- Implemented API request mapping to `POST /api/v2/mail/send`.
- Added automatic `x_track_id` generation when missing.
- Added forwarding of `X-Track-ID` in custom email headers.
- Kept callback processing and DNC mapping compatible with lite plugin.
- Extended callback transport guard to support Mailganer API DSN scheme.

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
