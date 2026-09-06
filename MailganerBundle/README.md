# Mailganer for Mautic

Mailganer (Samotpravil) API delivery, personalized JSON packages, delivery diagnostics, provider management and DNC callbacks for Mautic 5, 6 and 7. Maintained by Alexander Zlobin / Sales Snap.

## Install and enable

Download the **MailganerBundle** release asset and extract its `MailganerBundle` directory into Mautic's `plugins/` (or `docroot/plugins/` for Composer installations). The repository root is the separate SMTP-only Callback package; it is not the full plugin's installation directory.

Run `php bin/console mautic:plugins:reload` and `php bin/console cache:clear` as the Mautic PHP user. Enable **Mailganer (API + Callback)** in Plugins. When replacing Mailganer Callback, transfer its three handling switches and logging preference, then disable the Callback integration to avoid duplicate listeners with SMTP.

In Mautic's email configuration set the mailer DSN:

```text
mailganer+api://default?key=SMTP_API_KEY&batch_size=100
```

Use the key for the **SMTP service**, not the separate Mailganer marketing-account API key. The existing Bigart SMTP password was verified against `/api/v2/authkey` during release acceptance. For other accounts check with `mautic:mailganer:api account` before sending. URL-encode secrets containing reserved URI characters. Do not place real keys in shell history, repository files or support logs.

Supported DSN options:

| Option | Default | Meaning |
| --- | --- | --- |
| `batch_size` | `100` | Mautic token-batch size; integer from 1 to 1000. |
| `track_open`, `track_click` | `0` | Additional provider tracking; Mautic tracking remains in the prepared content. |
| `track_domain` | provider default | Previously configured provider tracking domain. |
| `check_local_stop_list` | `1` | Keep the provider's local suppressions enabled. |
| `raw` | `1` | Prevent the single-send endpoint from reinterpreting already-rendered Mautic content. |
| `force_package` | `0` | Also submit a compatible one-recipient token batch as a package, useful for acceptance tests. |

`?key=` takes priority over URI password, then URI user. Hosts are restricted to `api.samotpravil.ru` and `smtp.mailganer.com`; API uses HTTPS/443. Structured tracking IDs replace the old cosmetic `x_track_prefix` format so callbacks can recover the Mautic email ID. The old option does not alter attribution.

## Sending and packages

Single sends use `/api/v1/smtp_send`, including HTML, text alternatives, Reply-To and ordinary attachments. The old `/api/v2/mail/send` endpoint is now listed as deprecated by the provider.

Mautic's native `TokenTransportInterface` supplies batches. The plugin resolves each recipient's Mautic tokens before submitting `/api/v1/add_json_package`. Subject and HTML are separate recipient variables; one recipient cannot receive another recipient's content. Packages contain only messages with compatible shared sender/settings/headers and are split at `batch_size`. `external_id` identifies the package and retains its Mautic email ID for feedback.

The provider's documented JSON package format does not include a separate text/plain alternative or per-recipient header maps. **Content preservation is the default:** messages with plaintext alternatives, ordinary attachments or different personalized headers use the single-send API. In particular, unique List-Unsubscribe headers are preserved rather than replaced with another recipient's URL. This can mean a normal Mautic campaign uses individual API requests; no universal speed improvement is claimed.

Unsupported MIME is rejected before the first request: CC/BCC multi-recipient messages and inline CID attachments cannot silently disappear or become broken attachments. The provider documents CID attachments for its separate XML-fetch package format; that format is not implemented here. Keep such flows on an appropriate transport until they are converted or separately supported.

`raw=1` preserves literal template braces in singles; a live provider test showed that simply wrapping the original body in a template variable is insufficient. Do not send `X-Track-ID` twice: the API field generates the header itself. Provider-level global filtering is left at the account/API default, preserving the previous transport's behavior; the plugin does not clear stop lists or disable them automatically.

## Acceptance, failures and retries

A package acceptance (`S005`) is not delivery. Use both package status and package statistics. A completed package (`S018`) can still contain rejected or suppressed recipients. Delivery, failure, complaint and unsubscribe reports are available through the command below; DNC is applied from callbacks.

Receipts are stored outside the cache in `var/mailganer-receipts/<account-hash>/`. Keep this directory persistent, writable by the Mautic PHP/worker user, and shared by workers using the same installation. File locks coordinate concurrent retries; atomic writes preserve the previous pending receipt if a write fails. Receipts contain results and hashed identities, not message bodies or API keys.

On a retry, already accepted submissions are skipped. Definitively rejected requests remain retryable through Mautic's existing delivery flow. A timeout or malformed success is treated as uncertain, not as a reason to resend immediately. Packages are reconciled by `external_id`. Uncertain single sends require an operator to inspect delivery/history by `x_track_id` before deciding whether a retry is safe. The plugin does not introduce a second hidden delivery queue or claim exactly-once delivery from a provider without documented idempotency.

Do not delete receipts for messages that may still be retried. Back them up with the application state. For an uncertain result, retain the receipt and use provider diagnostics; do not clear the whole directory to unblock a queue. Invalid credentials, provider rejections, storage failures and unsupported messages surface as transport errors, including test sends. Provider quotas still apply; configure Mautic workers and campaign limits for the account's allowed rate.

## Provider operations

The enabled integration and its configured API DSN are used automatically. No key is accepted on the command line. Commands emit JSON; credential values are redacted.

```bash
php bin/console mautic:mailganer:api account
php bin/console mautic:mailganer:api domains
php bin/console mautic:mailganer:api domain-verify --data='{"domain":"example.com"}'
php bin/console mautic:mailganer:api delivery --data='{"x_track_id":"mtc-e42-h..."}'
php bin/console mautic:mailganer:api history --data='{"x_track_id":"mtc-e42-h..."}'
php bin/console mautic:mailganer:api package-status --data='{"external_id":"mtc-e42-p..."}'
php bin/console mautic:mailganer:api package-statistics --data='{"id":123456}'
php bin/console mautic:mailganer:api package-failures --data='{"issuen":123456}'
php bin/console mautic:mailganer:api package-stop --data='{"pack_id":123456}'
php bin/console mautic:mailganer:api statistics --data='{"date_from":"2026-09-01","date_to":"2026-09-06"}'
php bin/console mautic:mailganer:api stop-list-search --data='{"email":"recipient@example.com"}'
php bin/console mautic:mailganer:api stop-list-failed --data='{"limit":100}'
php bin/console mautic:mailganer:api stop-list-fbl --data='{"limit":100}'
php bin/console mautic:mailganer:api stop-list-unsubscribe --data='{"limit":100}'
```

Further actions: `non-delivery`, `complaints`, `unsubscribes` (date range), `mailing` (`id`), `stop-list-add` / `stop-list-remove` (`mail_from`, `email`). Paginated reports expose the provider cursor; pass `cursor_next` and `limit` in `--data` to continue. Provider stop-list removal does not erase Mautic's DNC or consent history. Package cancellation is an explicit operator action and does not automatically requeue messages.

To configure the provider callback for the correct mailing/account:

```bash
php bin/console mautic:mailganer:api mailing --data='{"id":1234}'
php bin/console mautic:mailganer:api webhook-configure --data='{"id":1234,"webhook_url":"https://mautic.example.com/mailer/callback"}'
```

This action changes only the selected mailing's webhook URL and activation flag. It does not overwrite sender state, stop-list settings or other account settings. Provider support can also configure the same endpoint.

## Callbacks and attribution

Enable handling of `failed`, `fbl` and `unsubscribe` in the plugin card. They produce email-channel DNC entries for bounces and unsubscribes. Both `messages` and `xml_messages` formats are supported. SMTP remains supported for callback processing when explicitly selected as the site's transport.

Only a validated positive Mautic email ID from explicit metadata or a structured tracking marker is used as `channel_id`. Numeric provider message IDs and arbitrary numeric tracking IDs are never treated as Mautic email IDs. Older notifications without usable metadata still update contact-level DNC and generate an attribution warning; historical rows are not guessed/backfilled. Existing links/tracking from Mautic remain authoritative; delivery/open/click provider reports do not create duplicate Mautic engagement events.

Database/storage exceptions produce HTTP 503 rather than acknowledging lost feedback. Invalid JSON produces 400. A per-contact database lock and persisted-state check prevent repeated callbacks from creating duplicate DNC rows; writes still use Mautic's native model. Unsubscribes are never downgraded by later bounces. Optional incoming-payload logging uses Mautic's logger; HTTP authorization headers are never dumped.

## Verification and provenance

See `COMPATIBILITY.md` and `LINEAGE_AUDIT.md`. Tests cover native kernels, callback database attribution, batches, personalization, retries, storage errors, malformed responses, credentials and attachments across supported Mautic versions. Live single/package delivery was tested using the owner's approved account and recipient; it is not a throughput benchmark or certification of every provider/account feature.

Provider references: [SMTP API documentation](https://documentation.samotpravil.ru/), [separate marketing-account authentication](https://mailganer.com/documentation/api).
