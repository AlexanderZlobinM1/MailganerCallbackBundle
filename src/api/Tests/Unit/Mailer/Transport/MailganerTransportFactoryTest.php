<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerBundle\Tests\Unit\Mailer\Transport;

use Mautic\PluginBundle\Entity\Integration as PluginIntegrationEntity;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use MauticPlugin\MailganerBundle\Mailer\Transport\MailganerApiTransport;
use MauticPlugin\MailganerBundle\Mailer\Transport\MailganerTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\Dsn;

class MailganerTransportFactoryTest extends TestCase
{
    public function testCreateTransportFromDsn(): void
    {
        $factory = new MailganerTransportFactory($this->createIntegrationHelper(true));

        $dsn = Dsn::fromString('mailganer+api://default?key=abc123&track_open=1&track_click=1&raw=1&x_track_prefix=mautic');
        $transport = $factory->create($dsn);

        self::assertInstanceOf(MailganerApiTransport::class, $transport);
        self::assertSame('mailganer+api://api.samotpravil.ru', (string) $transport);
    }

    public function testDisabledPluginDoesNotSupportOrCreateTransport(): void
    {
        $factory = new MailganerTransportFactory($this->createIntegrationHelper(false));
        $dsn     = Dsn::fromString('mailganer+api://default?key=abc123');

        self::assertFalse($factory->supports($dsn));

        $this->expectException(UnsupportedSchemeException::class);
        $factory->create($dsn);
    }

    private function createIntegrationHelper(bool $published): IntegrationHelper
    {
        $settings = $this->createMock(PluginIntegrationEntity::class);
        $settings->method('getIsPublished')->willReturn($published);

        $integration = $this->createMock(AbstractIntegration::class);
        $integration->method('getIntegrationSettings')->willReturn($settings);

        $helper = $this->createMock(IntegrationHelper::class);
        $helper->method('getIntegrationObject')->with('Mailganer')->willReturn($integration);

        return $helper;
    }
}
