<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerBundle;

use Mautic\IntegrationsBundle\Bundle\AbstractPluginBundle;

class MailganerBundle extends AbstractPluginBundle
{
    public const VERSION = '1.2.0';

    public const SUPPORTED_MAILER_HOSTS = [
        'api.samotpravil.ru',
        'smtp.mailganer.com',
    ];

    public const SUPPORTED_MAILER_SCHEMES = [
        'mailganer',
        'mailganer+api',
    ];
}
