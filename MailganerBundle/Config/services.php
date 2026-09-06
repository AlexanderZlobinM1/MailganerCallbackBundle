<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\MailganerBundle\Mailer\Transport\MailganerTransportFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $excludes = ['Mailer/Transport/MailganerApiTransport.php', 'Mailer/SubmissionStore.php', 'Api/'];

    // Legacy config.php treats FQCN arguments as strings, not service references.
    $services->alias('mailganerbundle.helper.encryption', Mautic\CoreBundle\Helper\EncryptionHelper::class);

    $services->load('MauticPlugin\\MailganerBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');

    $services->set(MailganerTransportFactory::class)
        ->arg('$journalDirectory', '%kernel.project_dir%/var/mailganer-receipts')
        ->tag('mailer.transport_factory');
};
