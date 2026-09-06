<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerCallbackBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;
use Mautic\PluginBundle\Entity\Plugin;
use MauticPlugin\MailganerCallbackBundle\Model\SharedSettings;

final class Version_1_2_0 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        return $schema->hasTable($this->concatPrefix('plugin_integration_settings'));
    }

    protected function up(): void
    {
        $plugin = $this->entityManager->getRepository(Plugin::class)->findOneBy(['bundle' => 'MailganerCallbackBundle']);
        SharedSettings::migrate($this->entityManager, $plugin);
        $this->entityManager->flush();
    }
}
