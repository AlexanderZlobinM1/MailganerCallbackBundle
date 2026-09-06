# Mautic Mailganer Callback

Plugin for Mautic 5/6/7 to process Mailganer (Samotpravil) webhook callbacks and mark contacts as Do Not Contact for email channel.

This plugin does not send email. Email sending is handled by Symfony SMTP transport configured in Mautic.

Company: Sales Snap
Author: Alexander Zlobin

## Supported mailer transports

The plugin handles callbacks only when Mautic SMTP DSN points to one of hosts:

- `api.samotpravil.ru`
- `smtp.mailganer.com`

## Processed statuses

The plugin handles these Mailganer statuses from `messages` and `xml_messages` arrays:

- `failed` -> `DoNotContact::BOUNCED`
- `fbl` -> `DoNotContact::UNSUBSCRIBED`
- `unsubscribe` -> `DoNotContact::UNSUBSCRIBED`

All other statuses (`accepted`, `delivered`, `open`, `click`, `duplicate`, etc.) are ignored.

## Installation

1. Copy plugin directory to your Mautic installation:

```bash
cp -R MailganerCallbackBundle /path/to/mautic/docroot/plugins/
```

Or install from ZIP by extracting `MailganerCallbackBundle` into:

```text
/path/to/mautic/docroot/plugins/MailganerCallbackBundle
```

2. Reload plugins and clear cache:

```bash
php bin/console mautic:plugins:reload
php bin/console cache:clear
```

3. Configure Mailganer webhook endpoint:

```text
https://mautic.example.com/mailer/callback
```

4. Ask Mailganer support to activate webhook for your sending domain and endpoint URL.

5. Open plugin card in Mautic Plugins and configure settings directly in plugin modal.

Use switches to enable/disable processing for `failed`, `fbl`, and `unsubscribe` statuses.
You can also enable incoming webhook logging (`Log incoming webhook payload`) for provider diagnostics.

## Notes

- Plugin accepts both webhook payload formats: `messages` (single sends) and `xml_messages` (batch sends).
- Email is extracted from `email`, `recipient`, `to`, or `address` field.
- Email attribution uses explicit `X-EMAIL-ID` metadata or a structured tracking marker; provider message IDs are not Mautic email IDs.
- When webhook logging is enabled, inspect Mautic logs (`var/logs/mautic_prod.php` or environment-specific log file) for records:
  - `Mailganer callback received`
  - `Mailganer callback processed summary`
- The plugin does not write a separate callback log file; it uses the standard Mautic logger.

## Two-plugin layout

The repository has one shared source directory and two variant directories.
CI builds independent `MailganerCallbackBundle` and `MailganerBundle` release
ZIPs from that tree. Download the named release asset; the GitHub source
archive is not directly installable. No second plugin or shared library is
needed at runtime. See the repository README for the build procedure.

## Release 1.1.3 attribution

Outgoing Mautic emails carry an explicit email ID and structured tracking marker. Callback attribution accepts those markers; arbitrary numeric `message_id` / `x_track_id` values are not Mautic IDs. Database failures return HTTP 503. See `LINEAGE_AUDIT.md` for the SES/SendGrid/Mailganer audit.

For API delivery and packages, install the separate **MailganerBundle** release asset and follow its included README. Install only one variant at a time.

## Shared settings and removal

Install only one Mailganer variant at a time. Both use integration `Mailganer`
and common fields `mailganer_handle_failed`, `mailganer_handle_fbl`,
`mailganer_handle_unsubscribe` and `mailganer_log_payload`. The plugin's native
lifecycle adopts legacy `MailganerCallback` settings and preserves existing
canonical values, including an explicit disabled state. Saving the Callback
form retains the full variant's rate and concurrency settings for a later switch.
MCC does not migrate or interpret these values.

A disabled general switch dims and locks the dependent fields without clearing
them. API sending, provider commands and callback processing remain gated by
publication state; already in-flight provider requests may finish.

MCD `remove` retains saved integration settings while removing files and plugin
registration. Explicit `purge` also deletes retained integration settings.
Keep submission receipts when upgrading or temporarily removing the full sender:
they protect against repeat delivery of previously accepted messages.
