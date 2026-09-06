<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerBundle\Tests\Unit;

use MauticPlugin\MailganerBundle\Api\ApiException;
use MauticPlugin\MailganerBundle\Mailer\SendRateLimiter;
use MauticPlugin\MailganerBundle\Mailer\SubmissionStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;

final class ConcurrentSendingTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mailganer-concurrent-'.bin2hex(random_bytes(8));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new \Symfony\Component\Filesystem\Filesystem())->remove($this->dir);
    }

    public function testWaveStartsTogetherAndDrainsAllResponsesAfterPartialFailure(): void
    {
        $store = new SubmissionStore($this->dir);
        $started = $finished = [];
        $start = function ($id) use (&$started) {
            $started[] = $id;

            return $id;
        };
        $finish = function ($id) use (&$started, &$finished) {
            self::assertCount(3, $started);
            $finished[] = $id;
            if ('b' === $id) {
                throw new ApiException('Rejected', true);
            }

            return ['message_id' => $id];
        };
        try {
            $store->submitWave(['c' => 'c', 'b' => 'b', 'a' => 'a'], $start, $finish);
            self::fail('Expected partial failure');
        } catch (ApiException) {
        }
        self::assertSame(['a', 'b', 'c'], $finished);
        $started = [];
        $result = $store->submitWave(['a' => 'a', 'b' => 'b', 'c' => 'c'], $start, fn ($id) => ['message_id' => $id]);
        self::assertSame(['b'], $started);
        self::assertCount(3, $result);
    }

    public function testFailedLaunchStillPersistsAlreadyStartedResponses(): void
    {
        $store = new SubmissionStore($this->dir);
        $finished = [];
        try {
            $store->submitWave(['a' => 'a', 'b' => 'b'], function ($id) { if ('b' === $id) { throw new ApiException('Timeout', false); }

return $id; }, function ($id) use (&$finished) {
                $finished[] = $id;

                return ['message_id' => $id];
            });
            self::fail();
        } catch (ApiException) {
        }
        self::assertSame(['a'], $finished);
        $started = [];
        try {
            $store->submitWave(['a' => 'a', 'b' => 'b'], function ($id) use (&$started) {
                $started[] = $id;

                return $id;
            }, fn ($id) => []);
            self::fail();
        } catch (TransportException $e) {
            self::assertStringContainsString('uncertain', $e->getMessage());
        }
        self::assertSame([], $started);
    }

    public function testSharedRateLimitAndLiveChangeWithDeterministicClock(): void
    {
        $now = 100.;
        $slept = 0.;
        $settings = ['rate' => 2, 'concurrency' => 4];
        $clock = static function () use (&$now): float { return $now; };
        $sleep = static function (float $s) use (&$now, &$slept): void {
            $now += $s;
            $slept += $s;
        };
        $one = new SendRateLimiter($this->dir, $clock, $sleep);
        $two = new SendRateLimiter($this->dir, $clock, $sleep);
        $get = static function () use (&$settings): array { return $settings; };
        self::assertSame(2, $one->acquire(10, $get));
        self::assertSame(2, $two->acquire(10, $get));
        self::assertGreaterThanOrEqual(1., $slept);
        $settings = ['rate' => 1, 'concurrency' => 1];
        self::assertSame(1, $one->acquire(10, $get));
        $settings['rate'] = 0;
        $this->expectException(TransportException::class);
        $two->acquire(1, $get);
    }

    public function testAdaptiveGrowthThrottleAndManualPriority(): void
    {
        $now = 100.;
        $clock = static function () use (&$now): float {return $now; };
        $sleep = static function (float $s) use (&$now): void {$now += $s; };
        $r = new SendRateLimiter($this->dir, $clock, $sleep);
        $auto = fn () => ['rate' => null, 'concurrency' => 4];
        $r->acquire(4, $auto);
        for ($i = 0; $i < 20; ++$i) {
            $r->feedback(false);
        }
        $state = json_decode(file_get_contents($this->dir.'/rate.json'), true);
        self::assertSame(11, $state['auto_rate']);
        $r->feedback(true, 3);
        $state = json_decode(file_get_contents($this->dir.'/rate.json'), true);
        self::assertSame(5, $state['auto_rate']);
        $r->acquire(1, $auto);
        self::assertGreaterThanOrEqual(103., $now);
        $r->acquire(1, fn () => ['rate' => 3, 'concurrency' => 1]);
        $state = json_decode(file_get_contents($this->dir.'/rate.json'), true);
        self::assertSame(3, $state['effective_rate']);
        self::assertSame('manual', $state['mode']);
    }

    public function testInvalidRateStorageStopsBeforeSending(): void
    {
        file_put_contents($this->dir.'/rate.json', 'not-json');
        $r = new SendRateLimiter($this->dir);
        $this->expectException(\JsonException::class);
        $r->acquire(1, fn () => ['rate' => 10, 'concurrency' => 4]);
    }

    public function testParallelPersonalMessagesPreserveTextAttachmentsAndUnsubscribeHeaders(): void
    {
        $requests = [];
        $calls = 0;
        $client = new \Symfony\Component\HttpClient\MockHttpClient(function ($method, $url, $options) use (&$requests, &$calls) {
            ++$calls;
            self::assertStringEndsWith('/api/v1/smtp_send', $url);
            $p = json_decode($options['body'], true);
            $requests[$p['email_to']] = $p;

            return new \Symfony\Component\HttpClient\Response\MockResponse(json_encode(['status' => 'ok', 'message_id' => $p['email_to']]));
        });
        $t = new \MauticPlugin\MailganerBundle\Mailer\Transport\MailganerApiTransport('key', client: $client, journalDirectory: $this->dir, limitsProvider: fn () => ['rate' => 10000, 'concurrency' => 4], usePackages: false);
        $m = (new \Mautic\EmailBundle\Mailer\Message\MauticMessage())->from('from@example.com')->to('a@example.com')->subject('Hello {name}')->html('<p>{name}</p>')->text('Text {name}')->attach('bytes', 'check.txt', 'text/plain');
        foreach (['a', 'b', 'c', 'd'] as $name) {
            $m->addMetadata($name.'@example.com', ['emailId' => 42, 'hashId' => $name, 'tokens' => ['{name}' => $name, '{listUnsubscribeHeader}' => '<https://example.com/unsubscribe/'.$name.'>']]);
        }
        $t->send($m);
        self::assertCount(4, $requests);
        foreach ($requests as $address => $p) {
            $name = $address[0];
            self::assertSame('Hello '.$name, $p['subject']);
            self::assertSame('Text '.$name, $p['message_text_plain']);
            self::assertSame('<p>'.$name.'</p>', $p['message_text']);
            self::assertSame('<https://example.com/unsubscribe/'.$name.'>', $p['headers']['List-Unsubscribe']);
            self::assertSame('bytes', base64_decode($p['attach_files'][0]['filebody']));
        }
        $t->send($m);
        self::assertSame(4, $calls);
        self::assertCount(4, $t->getLastSubmissions());
    }

    public function testPausedPackageDoesNotStartAnyRequest(): void
    {
        $client = new \Symfony\Component\HttpClient\MockHttpClient(function () { self::fail('Paused package must not reach the API.'); });
        $transport = new \MauticPlugin\MailganerBundle\Mailer\Transport\MailganerApiTransport('key', client: $client, journalDirectory: $this->dir, forcePackage: true, limitsProvider: fn () => ['rate' => 0, 'concurrency' => 4]);
        $message = (new \Mautic\EmailBundle\Mailer\Message\MauticMessage())->from('from@example.com')->to('to@example.com')->subject('Package')->html('<p>test</p>');
        $message->addMetadata('to@example.com', ['emailId' => 42, 'hashId' => 'pause-test']);
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('paused');
        $transport->send($message);
    }

    public function testStatusReportsAdaptiveCeilingManualOverrideAndCooldown(): void
    {
        $now = 100.;
        $limiter = new SendRateLimiter($this->dir, fn () => $now);
        self::assertSame(10, $limiter->status(['rate' => null, 'concurrency' => 4])['effective_rate']);
        $limiter->acquire(1, fn () => ['rate' => null, 'concurrency' => 4]);
        $limiter->feedback(true, 7);
        $status = $limiter->status(['rate' => null, 'concurrency' => 4]);
        self::assertSame(5, $status['effective_rate']);
        self::assertSame(7, $status['backoff_seconds']);
        self::assertSame(40, $limiter->status(['rate' => 40, 'concurrency' => 4])['effective_rate']);
        self::assertSame('paused', $limiter->status(['rate' => 0, 'concurrency' => 4])['mode']);
    }
}
