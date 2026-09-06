<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerBundle\Form;

use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SendingControls
{
    public static function append($builder, array $data, TranslatorInterface $translator): void
    {
        $builder->add('mailganer_send_rate', IntegerType::class, [
            'label' => 'sending.rate', 'translation_domain' => 'mailganer_api',
            'invalid_message' => $translator->trans('sending.rate.invalid', [], 'mailganer_api'),
            'required' => false, 'data' => $data['mailganer_send_rate'] ?? null,
            'attr' => ['min' => 0, 'max' => 10000, 'tooltip' => $translator->trans('sending.rate.help', [], 'mailganer_api')],
            'constraints' => [new Range(['min' => 0, 'max' => 10000, 'notInRangeMessage' => $translator->trans('sending.rate.invalid', [], 'mailganer_api')])],
        ]);
        $builder->add('mailganer_concurrency', IntegerType::class, [
            'label' => 'sending.concurrency', 'translation_domain' => 'mailganer_api',
            'invalid_message' => $translator->trans('sending.concurrency.invalid', [], 'mailganer_api'),
            'required' => true, 'data' => $data['mailganer_concurrency'] ?? 4,
            'attr' => ['min' => 1, 'max' => 32, 'tooltip' => $translator->trans('sending.concurrency.help', [], 'mailganer_api')],
            'constraints' => [new Range(['min' => 1, 'max' => 32, 'notInRangeMessage' => $translator->trans('sending.concurrency.invalid', [], 'mailganer_api')]), new NotBlank(['message' => $translator->trans('sending.concurrency.invalid', [], 'mailganer_api')])],
        ]);
    }
}
