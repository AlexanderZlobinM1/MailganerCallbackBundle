<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerBundle\Mailer\Transport;

use Symfony\Component\Mailer\Exception\IncompleteDsnException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

final class MailganerTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        $scheme = $dsn->getScheme();

        if (!in_array($scheme, $this->getSupportedSchemes(), true)) {
            throw new UnsupportedSchemeException($dsn, 'mailganer', $this->getSupportedSchemes());
        }

        $apiKey = $this->resolveApiKey($dsn);
        $host = 'default' === $dsn->getHost() ? null : $dsn->getHost();

        return (new MailganerApiTransport(
            $apiKey,
            $this->toBoolean($dsn->getOption('track_open'), false),
            $this->toBoolean($dsn->getOption('track_click'), false),
            $this->toBoolean($dsn->getOption('check_local_stop_list'), true),
            $this->toBoolean($dsn->getOption('raw'), true),
            $this->toNullableString($dsn->getOption('track_domain')),
            $this->toNullableString($dsn->getOption('x_track_prefix')),
            $this->client,
            $this->dispatcher,
            $this->logger
        ))
            ->setHost($host)
            ->setPort($dsn->getPort());
    }

    protected function getSupportedSchemes(): array
    {
        return ['mailganer', 'mailganer+api'];
    }

    private function resolveApiKey(Dsn $dsn): string
    {
        $apiKey = $dsn->getOption('key')
            ?? $dsn->getUser()
            ?? $dsn->getPassword();

        if (!is_string($apiKey) || '' === trim($apiKey)) {
            throw new IncompleteDsnException('Mailganer API key is missing. Use DSN user or ?key=... option.');
        }

        return trim($apiKey);
    }

    private function toNullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $stringValue = trim((string) $value);

        return '' === $stringValue ? null : $stringValue;
    }

    private function toBoolean(mixed $value, bool $default): bool
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

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }
}
