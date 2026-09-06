<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerBundle\Tests\Unit\Mailer\Transport;

use MauticPlugin\MailganerBundle\Mailer\Transport\MailganerApiTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

class MailganerApiTransportTest extends TestCase
{
    public function testSendBuildsExpectedPayload(): void
    {
        $capturedOptions = [];

        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions) {
            $capturedOptions = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            return new MockResponse('{"status":"OK","message_id":"provider-message-id"}', [
                'http_code' => 200,
            ]);
        });

        $transport = new MailganerApiTransport(
            'api-key-123',
            true,
            true,
            true,
            true,
            'track.example.com',
            'mautic',
            $client
        );

        $email = (new Email())
            ->from('Sender <sender@example.com>')
            ->to('recipient@example.com')
            ->subject('Test subject')
            ->html('<p>Body</p>');

        $sentMessage = $transport->send($email);

        self::assertNotNull($sentMessage);
        self::assertSame('provider-message-id', $sentMessage->getMessageId());
        self::assertSame('POST', $capturedOptions['method']);
        self::assertSame('https://api.samotpravil.ru/api/v1/smtp_send', $capturedOptions['url']);

        $payload = json_decode($capturedOptions['options']['body'], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('sender@example.com', \Symfony\Component\Mime\Address::create($payload['email_from'])->getAddress());
        self::assertSame('recipient@example.com', $payload['email_to']);
        self::assertSame('Test subject', $payload['subject']);
        self::assertSame('<p>Body</p>', $payload['message_text']);
        self::assertSame('track.example.com', $payload['track_domain']);
        self::assertTrue($payload['track_open']);
        self::assertTrue($payload['track_click']);
        self::assertTrue($payload['check_local_stop_list']);
        self::assertTrue($payload['raw']);
        self::assertArrayHasKey('x_track_id', $payload);
        self::assertNotSame('', $payload['x_track_id']);
        self::assertArrayNotHasKey('X-Track-ID', $payload['headers']);
        self::assertSame(
            ['Authorization: api-key-123'],
            $capturedOptions['options']['normalized_headers']['authorization']
        );
    }

    public function testProviderErrorStatusThrowsTransportException(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"status":"error","message":"bad request","code":550}', [
                'http_code' => 200,
            ]),
        ]);

        $transport = new MailganerApiTransport('api-key-123', client: $client);

        $email = (new Email())
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Test')
            ->html('<p>Body</p>');

        $this->expectException(TransportException::class);
        $transport->send($email);
    }
}
