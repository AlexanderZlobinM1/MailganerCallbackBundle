<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerBundle\Tests\Unit\Mailer\Transport;

use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use MauticPlugin\MailganerBundle\Api\ApiException;
use MauticPlugin\MailganerBundle\Mailer\SubmissionStore;
use MauticPlugin\MailganerBundle\Mailer\Transport\MailganerApiTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

final class BatchTransportTest extends TestCase
{
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($dir);
        }
    }

    private function directory(): string
    {
        $dir = sys_get_temp_dir().'/mailganer-unit-'.bin2hex(random_bytes(8));
        mkdir($dir);
        $this->directories[] = $dir;

        return $dir;
    }

    private function email(): Email
    {
        return (new Email())->from('Sender <from@example.com>')->to('a@example.com')->subject('Subject {{ literal }}')->html('<p>Literal {{ untouched }}</p>');
    }

    private function batch(int $count = 3): MauticMessage
    {
        $email = (new MauticMessage())->from('Sender <from@example.com>')->to('a@example.com')->subject('Hi {name}')->html('<a href="{link}">Hi {name}</a>');
        for ($i = 0; $i < $count; ++$i) {
            $email->addMetadata('a'.$i.'@example.com', ['hashId' => 'stat'.$i, 'emailId' => 42, 'tokens' => ['{name}' => 'Name '.$i, '{link}' => 'https://example.com/'.$i]]);
        }

        return $email;
    }

    public function testRealPackagesPreservePerRecipientBodiesAndSplitAtLimit(): void
    {
        $requests = [];
        $client = new MockHttpClient(function ($method, $url, $options) use (&$requests) {
            $requests[] = json_decode($options['body'], true);
            self::assertStringEndsWith('/api/v1/add_json_package', $url);

            return new MockResponse('{"status":"ok","message":{"status":"S005","pack_id":123}}');
        });
        $t = new MailganerApiTransport('key', client: $client, batchSize: 2, journalDirectory: $this->directory());
        $t->send($this->batch(4));
        self::assertCount(2, $requests);
        self::assertCount(2, $requests[0]['users']);
        self::assertSame('Hi Name 1', $requests[0]['users'][1]['mtc_subject']);
        self::assertSame('<a href="https://example.com/1">Hi Name 1</a>', $requests[0]['users'][1]['mtc_html']);
        self::assertStringStartsWith('mtc-e42-p', $requests[0]['external_id']);
        self::assertNotSame($requests[0]['external_id'], $requests[1]['external_id']);
        self::assertSame('42', $requests[0]['headers']['X-EMAIL-ID']);
    }

    public function testPartialRetryDoesNotResendAcceptedPackage(): void
    {
        $requests = 0;
        $client = new MockHttpClient(function () use (&$requests) {
            ++$requests;

            return new MockResponse(2 === $requests ? '{"status":"error","message":"rate limited"}' : '{"status":"ok","message":{"status":"S005","pack_id":123}}');
        });
        $t = new MailganerApiTransport('key', client: $client, batchSize: 2, journalDirectory: $this->directory());
        $email = $this->batch(4);
        try {
            $t->send($email);
            self::fail('Expected rejected second package');
        } catch (ApiException $e) {
            self::assertTrue($e->definitive);
        }
        $t->send($email);
        self::assertSame(3, $requests);
    }

    public function testUnknownSendOutcomeNeverBlindlyRetries(): void
    {
        $requests = 0;
        $dir = $this->directory();
        $store = new SubmissionStore($dir);
        $send = function () use (&$requests) {
            ++$requests;
            throw new ApiException('timeout', false);
        };
        try {
            $store->submit('id', $send);
        } catch (ApiException) {
        }
        try {
            $store->submit('id', $send);
            self::fail('Expected reconciliation error');
        } catch (TransportException $e) {
            self::assertStringContainsString('uncertain', $e->getMessage());
        }
        self::assertSame(1, $requests);
    }

    public function testPendingPackageCanRecoverByExternalIdWithoutSendingAgain(): void
    {
        $store = new SubmissionStore($this->directory());
        try {
            $store->submit('id', fn () => throw new ApiException('timeout', false));
        } catch (ApiException) {
        }
        $result = $store->submit('id', fn () => throw new \LogicException('duplicate'), fn () => ['pack_id' => '123']);
        self::assertSame(['pack_id' => '123'], $result);
    }

    public function testUnwritableStorageFailsBeforeNetwork(): void
    {
        $requests = 0;
        $path = $this->directory().'/file';
        file_put_contents($path, 'not a directory');
        $t = new MailganerApiTransport('key', client: new MockHttpClient(function () use (&$requests) {
            ++$requests;

            return new MockResponse('{}');
        }), journalDirectory: $path);
        try {
            $t->send($this->email());
            self::fail('Expected storage failure');
        } catch (TransportException $e) {
            self::assertStringContainsString('receipt', $e->getMessage());
        }
        self::assertSame(0, $requests);
    }

    public function testAlternativeTextAttachmentReplyToAndRawLiteralsArePreserved(): void
    {
        $payload = [];
        $t = new MailganerApiTransport('key', client: new MockHttpClient(function ($m, $u, $o) use (&$payload) {
            $payload = json_decode($o['body'], true);

            return new MockResponse('{"status":"OK","message_id":"id"}');
        }));
        $e = $this->email()->text('Plain <>& {{ untouched }}')->replyTo('Reply <reply@example.com>')->attach("\0\xffbinary", 'named.bin');
        $t->send($e);
        self::assertSame('Plain <>& {{ untouched }}', $payload['message_text_plain']);
        self::assertTrue($payload['raw']);
        self::assertSame('<p>Literal {{ untouched }}</p>', $payload['message_text']);
        self::assertSame("\0\xffbinary", base64_decode($payload['attach_files'][0]['filebody']));
        self::assertSame('named.bin', $payload['attach_files'][0]['name']);
        self::assertSame('Reply <reply@example.com>', $payload['headers']['Reply-To']);
    }

    public function testCcCannotBeSilentlyDropped(): void
    {
        $t = new MailganerApiTransport('key', client: new MockHttpClient(fn () => throw new \LogicException('unexpected send')));
        $this->expectException(TransportException::class);
        $t->send($this->email()->cc('b@example.com'));
    }

    public function testInlineCannotBeSilentlyCorrupted(): void
    {
        $t = new MailganerApiTransport('key', client: new MockHttpClient(fn () => throw new \LogicException('unexpected send')));
        $this->expectException(TransportException::class);
        $t->send($this->email()->embed('png', 'logo.png', 'image/png'));
    }

    public function testPersonalizedUnsubscribeHeadersNeverLeakBetweenRecipients(): void
    {
        $b = $this->batch(2);
        $b->clearMetadata();
        foreach (['a', 'b'] as $n) {
            $b->addMetadata($n.'@example.com', ['emailId' => 42, 'hashId' => $n, 'tokens' => ['{listUnsubscribeHeader}' => '<https://example.com/unsubscribe/'.$n.'>']]);
        }
        $requests = [];
        $t = new MailganerApiTransport('key', client: new MockHttpClient(function ($m, $u, $o) use (&$requests) {
            $requests[] = json_decode($o['body'], true);

            return new MockResponse('{"status":"OK","message_id":"id"}');
        }));
        $t->send($b);
        self::assertCount(2, $requests);
        self::assertSame('<https://example.com/unsubscribe/a>', $requests[0]['headers']['List-Unsubscribe']);
        self::assertSame('<https://example.com/unsubscribe/b>', $requests[1]['headers']['List-Unsubscribe']);
    }

    public function testMalformedSuccessIsNotAcknowledged(): void
    {
        $t = new MailganerApiTransport('key', client: new MockHttpClient([new MockResponse('{"status":"ok"}')]), journalDirectory: $this->directory());
        $this->expectException(ApiException::class);
        $t->send($this->email());
    }

    public function testProviderErrorNeverExposesKey(): void
    {
        $t = new MailganerApiTransport('PRIVATE-KEY', client: new MockHttpClient([new MockResponse('{"status":"error","message":"bad PRIVATE-KEY"}', ['http_code' => 403])]));
        try {
            $t->send($this->email());
            self::fail();
        } catch (ApiException $e) {
            self::assertStringNotContainsString('PRIVATE-KEY', $e->getMessage());
            self::assertTrue($e->definitive);
        }
    }
}
