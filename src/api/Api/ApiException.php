<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerBundle\Api;

use Symfony\Component\Mailer\Exception\TransportException;

final class ApiException extends TransportException
{
    public function __construct(string $message, public readonly bool $definitive, public readonly bool $throttled = false, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}
