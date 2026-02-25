# Changelog

## 1.0.0 - 2026-02-25

- Initial Mailganer (Samotpravil) callback plugin for Mautic 5/6/7.
- Added webhook processing for `failed`, `fbl`, `unsubscribe` statuses.
- Added support for both webhook payload envelopes: `messages` and `xml_messages`.
- Added integration modal switches for each supported status.
- Added SMTP transport host guard for `api.samotpravil.ru` and `smtp.mailganer.com`.
