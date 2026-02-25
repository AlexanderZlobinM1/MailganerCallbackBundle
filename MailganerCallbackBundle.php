<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerCallbackBundle;

use Mautic\IntegrationsBundle\Bundle\AbstractPluginBundle;

class MailganerCallbackBundle extends AbstractPluginBundle
{
    public const VERSION = '1.0.0';

    public const SUPPORTED_MAILER_HOSTS = [
        'api.samotpravil.ru',
        'smtp.mailganer.com',
    ];
}
