<?php

declare(strict_types=1);

use Mautic\CoreBundle\Helper\AppVersion;
use MauticPlugin\MailganerCallbackBundle\Integration\MailganerCallbackIntegration;

$mauticVersion = (int) (new AppVersion())->getVersion();

switch (true) {
    case $mauticVersion >= 6:
        $defaultIntegrationArguments = [
            'event_dispatcher',
            'mautic.helper.cache_storage',
            'doctrine.orm.entity_manager',
            'request_stack',
            'router',
            'translator',
            'monolog.logger.mautic',
            'mailganercallbackbundle.helper.encryption',
            'mautic.lead.model.lead',
            'mautic.lead.model.company',
            'mautic.helper.paths',
            'mautic.core.model.notification',
            'mautic.lead.model.field',
            'mautic.plugin.model.integration_entity',
            'mautic.lead.model.dnc',
            ...($mauticVersion >= 6 ? ['mautic.lead.field.fields_with_unique_identifier'] : []),
        ];
        break;
    case $mauticVersion >= 5:
        $defaultIntegrationArguments = [
            'event_dispatcher',
            'mautic.helper.cache_storage',
            'doctrine.orm.entity_manager',
            'session',
            'request_stack',
            'router',
            'translator',
            'monolog.logger.mautic',
            'mailganercallbackbundle.helper.encryption',
            'mautic.lead.model.lead',
            'mautic.lead.model.company',
            'mautic.helper.paths',
            'mautic.core.model.notification',
            'mautic.lead.model.field',
            'mautic.plugin.model.integration_entity',
            'mautic.lead.model.dnc',
            ...($mauticVersion >= 6 ? ['mautic.lead.field.fields_with_unique_identifier'] : []),
        ];
        break;
    default:
        $defaultIntegrationArguments = [];
}

return [
    'name' => 'Mailganer Callback',
    'description' => 'Mailganer (Samotpravil) callback processing for Mautic. Company: Sales Snap. Author: Alexander Zlobin. Copyright (c) Sales Snap.',
    'author' => 'Alexander Zlobin',
    'version' => '1.1.3',
    'services' => [
        'integrations' => [
            'mautic.integration.mailganercallback' => [
                'class' => MailganerCallbackIntegration::class,
                'arguments' => $defaultIntegrationArguments,
            ],
        ],
    ],
    'parameters' => [],
];
