<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerCallbackBundle;

final class Variant
{
    public const DISPLAY_NAME = 'Mailganer Callback';
    public const SUPPORTED_MAILER_SCHEMES = [];

    public static function appendSendingControls($builder, array $data, $translator): void
    {
    }
}
