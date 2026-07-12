<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerCallbackBundle\Tests\Functional\EventSubscriber;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\PluginBundle\Entity\Integration as PluginIntegrationEntity;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use MauticPlugin\MailganerCallbackBundle\EventSubscriber\CallbackSubscriber;
use MauticPlugin\MailganerCallbackBundle\Integration\MailganerCallbackIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CallbackSubscriberTest extends TestCase
{
    public function testProcessFailedAddsBouncedDnc(): void
    {
        $transportCallback = $this->createMock(TransportCallback::class);
        $transportCallback
            ->expects(self::once())
            ->method('addFailureByAddress')
            ->with(
                'john.doe@example.com',
                self::stringContains('mailbox not found'),
                DoNotContact::BOUNCED,
                42
            );

        $subscriber = $this->createSubscriber($transportCallback);

        $eventPayload = [
            'xml_messages' => [
                [
                    'status'     => 'failed',
                    'email'      => 'john.doe@example.com',
                    'reason'     => 'mailbox not found',
                    'x_track_id' => '42',
                ],
            ],
        ];

        self::assertSame(1, $this->invokeProcessPayload($subscriber, $eventPayload));
    }

    public function testProcessFblAddsUnsubscribedDnc(): void
    {
        $transportCallback = $this->createMock(TransportCallback::class);
        $transportCallback
            ->expects(self::once())
            ->method('addFailureByAddress')
            ->with(
                'john.doe@example.com',
                self::stringContains('fbl'),
                DoNotContact::UNSUBSCRIBED,
                null
            );

        $subscriber = $this->createSubscriber($transportCallback);

        $eventPayload = [
            'messages' => [
                [
                    'status' => 'fbl',
                    'email'  => 'john.doe@example.com',
                ],
            ],
        ];

        self::assertSame(1, $this->invokeProcessPayload($subscriber, $eventPayload));
    }

    public function testProcessDeliveredEventIgnored(): void
    {
        $transportCallback = $this->createMock(TransportCallback::class);
        $transportCallback
            ->expects(self::never())
            ->method('addFailureByAddress');

        $subscriber = $this->createSubscriber($transportCallback);

        $eventPayload = [
            'xml_messages' => [
                [
                    'status' => 'delivered',
                    'email'  => 'john.doe@example.com',
                ],
            ],
        ];

        self::assertSame(0, $this->invokeProcessPayload($subscriber, $eventPayload));
    }

    public function testDisabledFailedStatusIgnored(): void
    {
        $transportCallback = $this->createMock(TransportCallback::class);
        $transportCallback
            ->expects(self::never())
            ->method('addFailureByAddress');

        $subscriber = $this->createSubscriber($transportCallback, [
            'mailganer_callback_handle_failed' => false,
        ]);

        $eventPayload = [
            'xml_messages' => [
                [
                    'status' => 'failed',
                    'email'  => 'john.doe@example.com',
                    'reason' => 'mailbox not found',
                ],
            ],
        ];

        self::assertSame(0, $this->invokeProcessPayload($subscriber, $eventPayload));
    }

    public function testDisabledPluginSkipsAllEvents(): void
    {
        $transportCallback = $this->createMock(TransportCallback::class);
        $transportCallback
            ->expects(self::never())
            ->method('addFailureByAddress');

        $subscriber = $this->createSubscriber($transportCallback, [
            '__published' => false,
        ]);

        $eventPayload = [
            'messages' => [
                [
                    'status' => 'failed',
                    'email'  => 'john.doe@example.com',
                ],
            ],
        ];

        self::assertSame(0, $this->invokeProcessPayload($subscriber, $eventPayload));
    }

    public function testProcessCallbackLogsThroughMauticLogger(): void
    {
        $transportCallback = $this->createMock(TransportCallback::class);
        $transportCallback
            ->expects(self::once())
            ->method('addFailureByAddress')
            ->with(
                'john.doe@example.com',
                self::stringContains('mailbox not found'),
                DoNotContact::BOUNCED,
                42
            );

        $logger = $this->createMock(LoggerInterface::class);
        $infoMessages = [];
        $logger
            ->expects(self::exactly(3))
            ->method('info')
            ->willReturnCallback(static function (string $message, array $context = []) use (&$infoMessages): void {
                $infoMessages[$message] = $context;
            });
        $logger
            ->expects(self::never())
            ->method('warning');

        $subscriber = $this->createSubscriber($transportCallback, [
            'mailer_dsn' => 'smtp://api.samotpravil.ru:1126',
            'mailganer_callback_log_payload' => true,
        ], $logger);

        $request = Request::create(
            '/mailer/callback',
            'POST',
            [],
            [],
            [],
            [],
            json_encode([
                'xml_messages' => [
                    [
                        'status'     => 'failed',
                        'email'      => 'john.doe@example.com',
                        'reason'     => 'mailbox not found',
                        'x_track_id' => '42',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $event = $this->createMock(TransportWebhookEvent::class);
        $event
            ->method('getRequest')
            ->willReturn($request);
        $event
            ->expects(self::once())
            ->method('setResponse')
            ->with(self::callback(static fn (Response $response): bool => Response::HTTP_OK === $response->getStatusCode()));

        $subscriber->processCallbackRequest($event);

        self::assertArrayHasKey('Mailganer callback received', $infoMessages);
        self::assertStringContainsString('john.doe@example.com', $infoMessages['Mailganer callback received']['raw_body']);
        self::assertArrayHasKey('Processed Mailganer failed for john.doe@example.com', $infoMessages);
        self::assertArrayHasKey('Mailganer callback processed summary', $infoMessages);
        self::assertSame(1, $infoMessages['Mailganer callback processed summary']['processed']);
        self::assertSame(1, $infoMessages['Mailganer callback processed summary']['xml_messages_count']);
    }

    public function testProcessCallbackInvalidJsonLogsThroughMauticLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('info')
            ->with(
                'Mailganer callback received',
                self::callback(static fn (array $context): bool => '{bad' === $context['raw_body'])
            );
        $logger
            ->expects(self::once())
            ->method('warning')
            ->with(
                'Mailganer callback invalid JSON',
                self::callback(static fn (array $context): bool => isset($context['json_error']))
            );

        $subscriber = $this->createSubscriber($this->createMock(TransportCallback::class), [
            'mailer_dsn' => 'smtp://api.samotpravil.ru:1126',
            'mailganer_callback_log_payload' => true,
        ], $logger);

        $request = Request::create('/mailer/callback', 'POST', [], [], [], [], '{bad');

        $event = $this->createMock(TransportWebhookEvent::class);
        $event
            ->method('getRequest')
            ->willReturn($request);
        $event
            ->expects(self::once())
            ->method('setResponse')
            ->with(self::callback(static fn (Response $response): bool => Response::HTTP_BAD_REQUEST === $response->getStatusCode()));

        $subscriber->processCallbackRequest($event);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createSubscriber(TransportCallback $transportCallback, array $config = [], ?LoggerInterface $logger = null): CallbackSubscriber
    {
        $coreParametersHelper = $this->createMock(CoreParametersHelper::class);
        $coreParametersHelper
            ->method('get')
            ->willReturnCallback(static fn (string $key, mixed $default = null) => $config[$key] ?? $default);

        $integrationEntity = $this->createMock(PluginIntegrationEntity::class);
        $integrationEntity
            ->method('getIsPublished')
            ->willReturn($config['__published'] ?? true);

        $integration = $this->createMock(AbstractIntegration::class);
        $integration
            ->method('getKeys')
            ->willReturn($config);
        $integration
            ->method('getIntegrationSettings')
            ->willReturn($integrationEntity);

        $integrationHelper = $this->createMock(IntegrationHelper::class);
        $integrationHelper
            ->method('getIntegrationObject')
            ->with(MailganerCallbackIntegration::INTEGRATION_NAME)
            ->willReturn($integration);

        $logger ??= $this->createMock(LoggerInterface::class);

        return new CallbackSubscriber($transportCallback, $coreParametersHelper, $integrationHelper, $logger);
    }

    /**
     * @param array<string, mixed>|array<int, array<string, mixed>> $payload
     */
    private function invokeProcessPayload(CallbackSubscriber $subscriber, array $payload): int
    {
        $reflection = new \ReflectionMethod($subscriber, 'processPayload');
        $reflection->setAccessible(true);

        /** @var int $result */
        $result = $reflection->invoke($subscriber, $payload);

        return $result;
    }

}
