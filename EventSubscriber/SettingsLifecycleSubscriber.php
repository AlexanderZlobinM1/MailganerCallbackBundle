<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerCallbackBundle\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\PluginBundle\Event\PluginInstallEvent;
use Mautic\PluginBundle\PluginEvents;
use MauticPlugin\MailganerCallbackBundle\Model\SharedSettings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class SettingsLifecycleSubscriber implements EventSubscriberInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [PluginEvents::ON_PLUGIN_INSTALL => ['onInstall', -100]];
    }

    public function onInstall(PluginInstallEvent $event): void
    {
        if ('MailganerCallbackBundle' !== $event->getPlugin()->getBundle()) {
            return;
        }
        // Native reload persists the new plugin and this association in one flush.
        SharedSettings::migrate($this->entityManager, $event->getPlugin());
    }
}
