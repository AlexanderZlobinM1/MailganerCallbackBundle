<?php

declare(strict_types=1);

namespace MauticPlugin\__BUNDLE__\Model;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Entity\Plugin;

final class SharedSettings
{
    public static function migrate(EntityManagerInterface $em, ?Plugin $plugin = null): void
    {
        $rows = $em->getRepository(Integration::class)->findBy(['name' => ['Mailganer', 'MailganerCallback']]);
        $canonical = $legacy = null;
        foreach ($rows as $row) {
            if ('Mailganer' === $row->getName()) {
                $canonical = $row;
            } else {
                $legacy = $row;
            }
        }
        $target = $canonical ?? $legacy;
        if (null === $target) {
            return;
        }
        $keys = array_replace(self::normalize($legacy ? ($legacy->getApiKeys() ?? []) : []), self::normalize($canonical ? ($canonical->getApiKeys() ?? []) : []));
        $target->setName('Mailganer');
        $target->setApiKeys($keys);
        $target->setSupportedFeatures($target->getSupportedFeatures() ?? []);
        // Preserve the selected record's publication state, including explicit false.
        if (null !== $plugin) {
            $target->setPlugin($plugin);
        }
        $em->persist($target);
        if (null !== $canonical && null !== $legacy && $canonical !== $legacy) {
            $em->remove($legacy);
        }
    }

    /** @return array<string, mixed> */
    public static function normalize(array $keys): array
    {
        $result = $keys;
        foreach ($keys as $name => $value) {
            if (str_starts_with($name, 'mailganer_callback_')) {
                $canonical = 'mailganer_'.substr($name, strlen('mailganer_callback_'));
                if (!array_key_exists($canonical, $result)) {
                    $result[$canonical] = $value;
                }
                unset($result[$name]);
            }
        }

        return $result;
    }
}
