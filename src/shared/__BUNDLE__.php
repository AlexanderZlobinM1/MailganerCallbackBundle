<?php

declare(strict_types=1);

namespace MauticPlugin\__BUNDLE__;

use Mautic\IntegrationsBundle\Bundle\AbstractPluginBundle;

class __BUNDLE__ extends AbstractPluginBundle
{
    public const VERSION = '__VERSION__';

    public const SUPPORTED_MAILER_SCHEMES = Variant::SUPPORTED_MAILER_SCHEMES;

    public const SUPPORTED_MAILER_HOSTS = [
        'api.samotpravil.ru',
        'smtp.mailganer.com',
    ];
}
