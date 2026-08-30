#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LITE_DIR="$ROOT_DIR"
FULL_DIR="$ROOT_DIR/MailganerBundle"

if [[ ! -d "$FULL_DIR" ]]; then
  echo "MailganerBundle directory not found: $FULL_DIR" >&2
  exit 1
fi

copy_file() {
  local rel_path="$1"

  mkdir -p "$(dirname "$FULL_DIR/$rel_path")"
  cp "$LITE_DIR/$rel_path" "$FULL_DIR/$rel_path"
}

rewrite_full_names() {
  local file_path="$1"

  perl -0777 -i -pe '
    s/MauticPlugin\\MailganerCallbackBundle/MauticPlugin\\MailganerBundle/g;
    s/MailganerCallbackBundle/MailganerBundle/g;
    s/MailganerCallbackIntegration/MailganerIntegration/g;
    s/\@MailganerCallback/\@Mailganer/g;
    s/mailganercallback/mailganer/g;
    s/mailganer_callback/mailganer/g;
    s/Mailganer Callback/Mailganer/g;
  ' "$file_path"
}

echo "Syncing shared callback files from lite -> full..."

copy_file "Assets/img/icon.svg"
copy_file "Assets/img/icon.png"
copy_file "EventSubscriber/CallbackSubscriber.php"
copy_file "Tests/Functional/CallbackSubscriberTest.php"
copy_file "Translations/en_US/messages.ini"
copy_file "Translations/ru_RU/messages.ini"
copy_file "Translations/sr_RS/messages.ini"
cp "$LITE_DIR/Integration/MailganerCallbackIntegration.php" "$FULL_DIR/Integration/MailganerIntegration.php"

rewrite_full_names "$FULL_DIR/EventSubscriber/CallbackSubscriber.php"
rewrite_full_names "$FULL_DIR/Tests/Functional/CallbackSubscriberTest.php"
rewrite_full_names "$FULL_DIR/Translations/en_US/messages.ini"
rewrite_full_names "$FULL_DIR/Translations/ru_RU/messages.ini"
rewrite_full_names "$FULL_DIR/Translations/sr_RS/messages.ini"
rewrite_full_names "$FULL_DIR/Integration/MailganerIntegration.php"

perl -0777 -i -pe '
  s/INTEGRATION_NAME = '\''MailganerCallback'\''/INTEGRATION_NAME = '\''Mailganer'\''/g;
  s/return '\''Mailganer'\'';/return '\''Mailganer (API + Callback)'\'';/g;
' "$FULL_DIR/Integration/MailganerIntegration.php"

# Full plugin additionally supports custom API mailer scheme.
perl -0777 -i -pe '
  s@if \(in_array\(\$scheme, \['\''smtp'\'', '\''smtps'\''\], true\)
                && in_array\(\$host, MailganerBundle::SUPPORTED_MAILER_HOSTS, true\)\) \{
                return true;
            \}@if (in_array(\$scheme, MailganerBundle::SUPPORTED_MAILER_SCHEMES, true)) {
                return true;
            }

            if (in_array(\$scheme, ['\''smtp'\'', '\''smtps'\''], true)
                && in_array(\$host, MailganerBundle::SUPPORTED_MAILER_HOSTS, true)) {
                return true;
            }@s;

' "$FULL_DIR/EventSubscriber/CallbackSubscriber.php"

echo "Sync complete."
