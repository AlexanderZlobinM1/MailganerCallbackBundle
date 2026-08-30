<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerBundle\Tests\Unit\Mailer\Transport;

use MauticPlugin\MailganerBundle\Mailer\Transport\MailganerApiTransport;
use MauticPlugin\MailganerBundle\Mailer\Transport\MailganerTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\Dsn;

class MailganerTransportFactoryTest extends TestCase
{
    public function testCreateTransportFromDsn(): void
    {
        $factory = new MailganerTransportFactory();

        $dsn = Dsn::fromString('mailganer+api://default?key=abc123&track_open=1&track_click=1&raw=1&x_track_prefix=mautic');
        $transport = $factory->create($dsn);

        self::assertInstanceOf(MailganerApiTransport::class, $transport);
        self::assertSame('mailganer+api://api.samotpravil.ru', (string) $transport);
    }
}
