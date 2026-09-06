<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerBundle;

final class Variant
{
    public const DISPLAY_NAME = 'Mailganer (API + Callback)';
    public const SUPPORTED_MAILER_SCHEMES = ['mailganer', 'mailganer+api'];

    public static function appendSendingControls($builder, array $data, $translator): void
    {
        Form\SendingControls::append($builder, $data, $translator);
    }
}
