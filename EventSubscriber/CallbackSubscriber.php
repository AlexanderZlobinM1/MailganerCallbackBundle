<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerCallbackBundle\EventSubscriber;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use MauticPlugin\MailganerCallbackBundle\Integration\MailganerCallbackIntegration;
use MauticPlugin\MailganerCallbackBundle\MailganerCallbackBundle;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Address;

class CallbackSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TransportCallback $transportCallback,
        private CoreParametersHelper $coreParametersHelper,
        private IntegrationHelper $integrationHelper,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::ON_TRANSPORT_WEBHOOK => 'processCallbackRequest',
        ];
    }

    public function processCallbackRequest(TransportWebhookEvent $event): void
    {
        if (!$this->isPluginEnabled() || !$this->isSupportedMailerTransport()) {
            return;
        }

        $payload = json_decode($event->getRequest()->getContent(), true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            $event->setResponse(new Response('Invalid JSON', Response::HTTP_BAD_REQUEST));

            return;
        }

        if (!is_array($payload)) {
            $event->setResponse(new Response('Invalid payload', Response::HTTP_BAD_REQUEST));

            return;
        }

        try {
            $processed = $this->processPayload($payload);
            $event->setResponse(new Response(sprintf('Mailganer Callback processed (%d)', $processed)));
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to process Mailganer payload: '.$exception->getMessage());
            $event->setResponse(new Response('Bad Request', Response::HTTP_BAD_REQUEST));
        }
    }

    private function isSupportedMailerTransport(): bool
    {
        $dsnString = (string) $this->coreParametersHelper->get('mailer_dsn');

        if ('' === $dsnString) {
            return false;
        }

        try {
            $dsn = Dsn::fromString($dsnString);
            $scheme = strtolower($dsn->getScheme());
            $host   = strtolower((string) $dsn->getHost());

            if (in_array($scheme, ['smtp', 'smtps'], true)
                && in_array($host, MailganerCallbackBundle::SUPPORTED_MAILER_HOSTS, true)) {
                return true;
            }
        } catch (\InvalidArgumentException) {
            // Fall back to string-based check for compound transports such as failover().
        }

        return (bool) preg_match('/smtps?:\/\/[^\s@)]+@?(api\.samotpravil\.ru|smtp\.mailganer\.com)(:[0-9]+)?/i', $dsnString);
    }

    /**
     * @param array<string, mixed>|array<int, array<string, mixed>> $payload
     */
    private function processPayload(array $payload): int
    {
        if ($this->isAssoc($payload)) {
            return $this->processEnvelope($payload);
        }

        $processed = 0;

        foreach ($payload as $item) {
            if (!is_array($item)) {
                continue;
            }

            $processed += $this->processEnvelope($item);
        }

        return $processed;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function processEnvelope(array $payload): int
    {
        $processed = 0;

        foreach (['messages', 'xml_messages'] as $collectionKey) {
            if (!isset($payload[$collectionKey]) || !is_array($payload[$collectionKey])) {
                continue;
            }

            foreach ($payload[$collectionKey] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $processed += $this->processEvent($item);
            }
        }

        if ($processed > 0) {
            return $processed;
        }

        return $this->processEvent($payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function processEvent(array $payload): int
    {
        $status = strtolower((string) ($payload['status'] ?? ''));
        if ('' === $status || !$this->isStatusEnabled($status)) {
            return 0;
        }

        $email = $this->extractEmail($payload);
        if (null === $email) {
            $this->logger->warning(sprintf('Skipping Mailganer event "%s" because email is missing.', $status));

            return 0;
        }

        try {
            $address = Address::create($email)->getAddress();
            $emailId = $this->getEmailId($payload);
            $reason  = $this->buildReason($payload, $status);
            $dncType = $this->resolveStatusType($status);

            if (null === $dncType) {
                return 0;
            }

            $this->transportCallback->addFailureByAddress(
                $address,
                $reason,
                $dncType,
                $emailId
            );

            $this->logger->info(sprintf('Processed Mailganer %s for %s', $status, $address));

            return 1;
        } catch (\Throwable $exception) {
            $this->logger->warning('Skipping invalid Mailganer event: '.$exception->getMessage());
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildReason(array $payload, string $fallback): string
    {
        $parts = [
            $payload['reason'] ?? null,
            $payload['message'] ?? null,
            $payload['status'] ?? null,
            $payload['code'] ?? null,
        ];

        $parts = array_values(array_filter(array_map(static fn ($value): ?string => is_scalar($value) && '' !== (string) $value ? (string) $value : null, $parts)));

        if ([] === $parts) {
            return $fallback;
        }

        return implode(' | ', $parts);
    }

    private function resolveStatusType(string $status): ?int
    {
        return match ($status) {
            'failed' => DoNotContact::BOUNCED,
            'fbl', 'unsubscribe' => DoNotContact::UNSUBSCRIBED,
            default => null,
        };
    }

    private function isStatusEnabled(string $status): bool
    {
        $parameterMap = [
            'failed'      => 'mailganer_callback_handle_failed',
            'fbl'         => 'mailganer_callback_handle_fbl',
            'unsubscribe' => 'mailganer_callback_handle_unsubscribe',
        ];

        if (!isset($parameterMap[$status])) {
            return false;
        }

        return $this->toBoolean($this->getIntegrationKey($parameterMap[$status]), true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractEmail(array $payload): ?string
    {
        foreach (['email', 'recipient', 'to', 'address'] as $field) {
            if (!isset($payload[$field]) || !is_scalar($payload[$field])) {
                continue;
            }

            $value = trim((string) $payload[$field]);
            if ('' !== $value) {
                return $value;
            }
        }

        return null;
    }

    private function isPluginEnabled(): bool
    {
        $integration = $this->getIntegrationObject();
        if (!$integration instanceof AbstractIntegration) {
            return false;
        }

        return (bool) $integration->getIntegrationSettings()->getIsPublished();
    }

    private function toBoolean(mixed $value, bool $default = false): bool
    {
        if (null === $value) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return 1 === $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function getEmailId(array $payload): ?int
    {
        foreach (['x_track_id', 'message_id'] as $field) {
            if (!isset($payload[$field]) || !is_scalar($payload[$field])) {
                continue;
            }

            $value = (string) $payload[$field];
            if (ctype_digit($value)) {
                return (int) $value;
            }

            if (preg_match('/X-EMAIL-ID[:=]([0-9]+)/i', $value, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $value
     */
    private function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function getIntegrationKey(string $key): mixed
    {
        $integration = $this->getIntegrationObject();
        if (!$integration instanceof AbstractIntegration) {
            return null;
        }

        $keys = $integration->getKeys();

        return $keys[$key] ?? null;
    }

    private function getIntegrationObject(): ?AbstractIntegration
    {
        $integration = $this->integrationHelper->getIntegrationObject(MailganerCallbackIntegration::INTEGRATION_NAME);

        return $integration instanceof AbstractIntegration ? $integration : null;
    }
}
