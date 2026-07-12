<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerCallbackBundle\Tests\Functional\EventSubscriber;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\PluginBundle\Entity\Integration as PluginIntegrationEntity;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use MauticPlugin\MailganerCallbackBundle\EventSubscriber\CallbackSubscriber;
use MauticPlugin\MailganerCallbackBundle\Integration\MailganerCallbackIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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

    public function testResolveMauticRootForClassicPluginLayout(): void
    {
        $subscriber = $this->createSubscriber($this->createMock(TransportCallback::class));
        $projectRoot = $this->createMauticProjectRoot();
        $eventSubscriberDir = $projectRoot.'/plugins/MailganerCallbackBundle/EventSubscriber';
        mkdir($eventSubscriberDir, 0775, true);

        self::assertSame($projectRoot, $this->invokeResolveMauticRoot($subscriber, $eventSubscriberDir));
    }

    public function testResolveMauticRootForComposerDocrootPluginLayout(): void
    {
        $subscriber = $this->createSubscriber($this->createMock(TransportCallback::class));
        $projectRoot = $this->createMauticProjectRoot();
        $eventSubscriberDir = $projectRoot.'/docroot/plugins/MailganerCallbackBundle/EventSubscriber';
        mkdir($eventSubscriberDir, 0775, true);

        self::assertSame($projectRoot, $this->invokeResolveMauticRoot($subscriber, $eventSubscriberDir));
    }

    public function testResolveConfiguredMauticRootUsesProjectParameter(): void
    {
        $projectRoot = $this->createMauticProjectRoot();
        $subscriber = $this->createSubscriber($this->createMock(TransportCallback::class), [
            'kernel.project_dir' => $projectRoot,
        ]);

        self::assertSame($projectRoot, $this->invokeResolveConfiguredMauticRoot($subscriber));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createSubscriber(TransportCallback $transportCallback, array $config = []): CallbackSubscriber
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

        $logger = $this->createMock(LoggerInterface::class);

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

    private function invokeResolveMauticRoot(CallbackSubscriber $subscriber, string $startDir): ?string
    {
        $reflection = new \ReflectionMethod($subscriber, 'resolveMauticRoot');
        $reflection->setAccessible(true);

        /** @var ?string $result */
        $result = $reflection->invoke($subscriber, $startDir);

        return $result;
    }

    private function invokeResolveConfiguredMauticRoot(CallbackSubscriber $subscriber): ?string
    {
        $reflection = new \ReflectionMethod($subscriber, 'resolveConfiguredMauticRoot');
        $reflection->setAccessible(true);

        /** @var ?string $result */
        $result = $reflection->invoke($subscriber);

        return $result;
    }

    private function createMauticProjectRoot(): string
    {
        $projectRoot = sys_get_temp_dir().'/mailganer-root-'.bin2hex(random_bytes(8));
        mkdir($projectRoot.'/bin', 0775, true);
        mkdir($projectRoot.'/var/logs', 0775, true);
        touch($projectRoot.'/bin/console');

        return $projectRoot;
    }
}
