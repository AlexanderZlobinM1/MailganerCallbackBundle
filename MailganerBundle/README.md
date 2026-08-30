# Mautic Mailganer (Full)

Plugin for Mautic 5/6/7 with full Mailganer integration:

- email sending via Mailganer API (`/api/v2/mail/send`)
- webhook callback processing (`/mailer/callback`) for DNC updates

Company: Sales Snap  
Author: Alexander Zlobin

## Installation

1. Copy plugin directory:

```bash
cp -R MailganerBundle /path/to/mautic/docroot/plugins/
```

2. Reload plugins and clear cache:

```bash
php bin/console mautic:plugins:reload
php bin/console cache:clear
```

3. Enable plugin in Mautic Plugins UI.

## Mailer DSN (API sending)

Set Mautic mailer DSN to Mailganer API transport:

```text
mailganer+api://default?key=YOUR_MAILGANER_API_KEY
```

Supported optional DSN options:

- `track_open=1|0`
- `track_click=1|0`
- `check_local_stop_list=1|0` (default `1`)
- `raw=1|0` (default `1`)
- `track_domain=track.your-domain.tld`
- `x_track_prefix=your-prefix`

Example:

```text
mailganer+api://default?key=YOUR_MAILGANER_API_KEY&track_open=1&track_click=1&x_track_prefix=5662
```

## Callback / DNC processing

Configure webhook URL in Mailganer support:

```text
https://mautic.example.com/mailer/callback
```

The plugin processes statuses from `messages` and `xml_messages`:

- `failed` -> `DoNotContact::BOUNCED`
- `fbl` -> `DoNotContact::UNSUBSCRIBED`
- `unsubscribe` -> `DoNotContact::UNSUBSCRIBED`

## Logging

If `Log incoming webhook payload` is enabled in plugin settings, logs are written to:

- Mautic default log (`var/logs/mautic_*.php`)
- dedicated file `var/logs/Mailganer.log`

## API Notes

- Provider documentation: https://documentation.samotpravil.ru/
- Transport sends `x_track_id` for each message.
- The same value is also forwarded in custom mail header `X-Track-ID`.
