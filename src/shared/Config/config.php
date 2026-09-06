<?php

declare(strict_types=1);

use Mautic\CoreBundle\Helper\AppVersion;
use MauticPlugin\__BUNDLE__\Integration\MailganerIntegration;

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
            '__SERVICE__.helper.encryption',
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
            '__SERVICE__.helper.encryption',
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
    'name' => '__DISPLAY__',
    'description' => '__DESCRIPTION__',
    'author' => 'Alexander Zlobin',
    'version' => '__VERSION__',
    'services' => [
        'integrations' => [
            'mautic.integration.mailganer' => [
                'class' => MailganerIntegration::class,
                'arguments' => $defaultIntegrationArguments,
            ],
        ],
    ],
    'parameters' => [],
];
