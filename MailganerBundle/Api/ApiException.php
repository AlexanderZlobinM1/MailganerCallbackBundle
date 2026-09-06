<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerBundle\Api;

use Symfony\Component\Mailer\Exception\TransportException;

final class ApiException extends TransportException
{
    public function __construct(string $message, public readonly bool $definitive)
    {
        parent::__construct($message);
    }
}
