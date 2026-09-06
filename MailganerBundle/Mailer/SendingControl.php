<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerBundle\Mailer;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\EncryptionHelper;
use Mautic\PluginBundle\Entity\Integration;
use Symfony\Component\Mailer\Exception\TransportException;

/** Read scalar database state so long-running workers see saved changes without stale ORM entities. */
final class SendingControl
{
    public function __construct(private EntityManagerInterface $em, private EncryptionHelper $encryption)
    {
    }

    public function current(): array
    {
        $row = $this->em->createQuery('SELECT i.isPublished, i.apiKeys FROM '.Integration::class.' i WHERE i.name = :name')
            ->setParameter('name', 'Mailganer')->getOneOrNullResult();
        if (!$row || !$row['isPublished']) {
            throw new TransportException('Mailganer integration is disabled; no new requests started.');
        }
        $limits = ['rate' => null, 'concurrency' => 4];
        foreach (['rate' => 'mailganer_send_rate', 'concurrency' => 'mailganer_concurrency'] as $field => $key) {
            if (isset($row['apiKeys'][$key])) {
                $limits[$field] = $this->encryption->decrypt($row['apiKeys'][$key]);
            }
        }
        if ('' === $limits['rate']) {
            $limits['rate'] = null;
        }

        return self::validate($limits);
    }

    public function save(array $limits): array
    {
        $limits = self::validate($limits);
        $entity = $this->em->getRepository(Integration::class)->findOneBy(['name' => 'Mailganer']);
        if (!$entity || !$entity->getIsPublished()) {
            throw new TransportException('Mailganer integration is disabled.');
        }
        $keys = $entity->getApiKeys();
        $keys['mailganer_send_rate'] = $this->encryption->encrypt($limits['rate'] ?? '');
        $keys['mailganer_concurrency'] = $this->encryption->encrypt($limits['concurrency']);
        $entity->setApiKeys($keys);
        $this->em->persist($entity); // Mautic uses explicit change tracking.
        $this->em->flush();

        return $this->current();
    }

    public static function validate(array $limits): array
    {
        foreach (['rate' => [0, 10000], 'concurrency' => [1, 32]] as $key => [$min, $max]) {
            if ('rate' === $key && null === ($limits[$key] ?? null)) {
                $limits[$key] = null;
                continue;
            }
            $value = filter_var($limits[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
            if (false === $value) {
                throw new TransportException('Invalid Mailganer '.$key.' limit.');
            }
            $limits[$key] = $value;
        }

        return $limits;
    }
}
