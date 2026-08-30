<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerBundle\Integration;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Mautic\PluginBundle\Integration\AbstractIntegration;

class MailganerIntegration extends AbstractIntegration
{
    public const INTEGRATION_NAME = 'Mailganer';

    public function getName(): string
    {
        return self::INTEGRATION_NAME;
    }

    public function getDisplayName(): string
    {
        return 'Mailganer (API + Callback)';
    }

    public function getIcon(): string
    {
        return 'plugins/MailganerBundle/Assets/img/icon.svg';
    }

    public function getAuthenticationType(): string
    {
        return 'none';
    }

    /**
     * @return array<string, string>
     */
    public function getRequiredKeyFields(): array
    {
        return [];
    }

    /**
     * @param mixed $builder
     * @param array<string, mixed> $data
     */
    public function appendToForm(&$builder, $data, $formArea): void
    {
        if ('keys' !== $formArea) {
            return;
        }

        $builder->add('mailganer_handle_failed', YesNoButtonGroupType::class, [
            'label' => 'mailganer.config.handle_failed',
            'data'  => $this->toBool($data['mailganer_handle_failed'] ?? true),
            'row_attr' => [
                'style' => 'margin-top: 30px;',
            ],
            'attr'  => [
                'tooltip' => 'mailganer.config.handle_failed.tooltip',
            ],
        ]);

        $builder->add('mailganer_handle_fbl', YesNoButtonGroupType::class, [
            'label' => 'mailganer.config.handle_fbl',
            'data'  => $this->toBool($data['mailganer_handle_fbl'] ?? true),
            'attr'  => ['tooltip' => 'mailganer.config.handle_fbl.tooltip'],
        ]);

        $builder->add('mailganer_handle_unsubscribe', YesNoButtonGroupType::class, [
            'label' => 'mailganer.config.handle_unsubscribe',
            'data'  => $this->toBool($data['mailganer_handle_unsubscribe'] ?? true),
            'attr'  => ['tooltip' => 'mailganer.config.handle_unsubscribe.tooltip'],
        ]);

        $builder->add('mailganer_log_payload', YesNoButtonGroupType::class, [
            'label' => 'mailganer.config.log_payload',
            'data'  => $this->toBool($data['mailganer_log_payload'] ?? false),
            'attr'  => ['tooltip' => 'mailganer.config.log_payload.tooltip'],
        ]);
    }

    /**
     * @return string|array<string, mixed>
     */
    public function getFormNotes($section)
    {
        if ('custom' === $section) {
            return [
                'custom'     => true,
                'template'   => '@Mailganer/Integration/footer.html.twig',
                'parameters' => [],
            ];
        }

        return parent::getFormNotes($section);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
