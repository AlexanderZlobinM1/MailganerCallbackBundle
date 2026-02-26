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
- Optional message email ID is extracted from numeric `x_track_id` or `message_id` when possible.
- When webhook logging is enabled, inspect Mautic logs (`var/logs/mautic_prod.php` or environment-specific log file) for records:
  - `Mailganer callback received`
  - `Mailganer callback processed summary`
- A dedicated JSONL file is also written to `var/logs/Mailganer.log`.
