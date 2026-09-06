<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerBundle\Tests\Unit;

use MauticPlugin\MailganerBundle\Api\ApiException;
use MauticPlugin\MailganerBundle\Api\MailganerApi;
use MauticPlugin\MailganerBundle\Command\ApiCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ApiTest extends TestCase
{
    public function testStopListWritesUseDocumentedQueryParameters(): void
    {
        $client = new MockHttpClient(function ($method, $url, $options) {
            self::assertSame('POST', $method);
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            self::assertSame('to@example.com', $query['email']);
            self::assertStringNotContainsString('secret', $url);

            return new MockResponse('{"status":"ok"}');
        });
        $api = new MailganerApi($client, 'secret');
        self::assertSame(['status' => 'ok'], $api->request('POST', '/api/v2/stop-list/add', ['email' => 'to@example.com', 'mail_from' => 'from@example.com']));
    }

    public function testAccountOutputPreservesUsefulMetadataAndRemovesOnlySecrets(): void
    {
        self::assertSame(['api_keys' => [['id' => 7, 'api_key' => '[redacted]', 'allowed_ips' => ['127.0.0.1']]]], ApiCommand::redact(['api_keys' => [['id' => 7, 'api_key' => 'secret', 'allowed_ips' => ['127.0.0.1']]]]));
    }

    public function testServerFailureIsAnUncertainOutcome(): void
    {
        $api = new MailganerApi(new MockHttpClient([new MockResponse('{"status":"error","message":"server error"}', ['http_code' => 503])]), 'key');
        try {
            $api->request('POST', '/api/v1/smtp_send', []);
            self::fail();
        } catch (ApiException $e) {
            self::assertFalse($e->definitive);
        }
    }

    public function testRedirectCannotExfiltrateAuthorization(): void
    {
        $client = new MockHttpClient(function ($method, $url, $options) {
            self::assertSame(0, $options['max_redirects']);

            return new MockResponse('{"status":"error"}', ['http_code' => 302]);
        });
        $this->expectException(ApiException::class);
        (new MailganerApi($client, 'key'))->request('GET', '/api/v2/authkey');
    }

    public function testProviderThrottleAndRetryAfterAreExposedWithoutCredentials(): void
    {
        $client = new MockHttpClient(new MockResponse('{"status":"throttling","message":"slow down"}', ['http_code' => 429, 'response_headers' => ['retry-after: 2']]));
        $api = new MailganerApi($client, 'key');
        try {
            $api->request('POST', '/api/v1/smtp_send', []);
            self::fail();
        } catch (ApiException $e) {
            self::assertTrue($e->definitive);
            self::assertTrue($e->throttled);
            self::assertSame(2, $e->retryAfter);
        }
    }

    public function testNonJsonThrottleStillActivatesCooldown(): void
    {
        $api = new MailganerApi(new MockHttpClient(new MockResponse('<html>rate limited</html>', ['http_code' => 429, 'response_headers' => ['retry-after: 3']])), 'key');
        try {
            $api->request('POST', '/api/v1/smtp_send');
            self::fail();
        } catch (ApiException $e) {
            self::assertTrue($e->definitive);
            self::assertTrue($e->throttled);
            self::assertSame(3, $e->retryAfter);
        }
    }
}
