<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerBundle\Api;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** SMTP-service API; credentials never appear in URLs, exceptions or logs. */
final class MailganerApi
{
    public function __construct(
        private HttpClientInterface $client,
        #[\SensitiveParameter] private string $key,
        private string $host = 'api.samotpravil.ru',
    ) {
        if (!in_array($host, ['api.samotpravil.ru', 'smtp.mailganer.com'], true)) {
            throw new TransportException('Unsupported Mailganer API host.');
        }
    }

    public function request(string $method, string $path, array $data = []): array
    {
        $options = ['headers' => ['Authorization' => $this->key], 'timeout' => 30, 'max_duration' => 60, 'max_redirects' => 0];
        $queryOnly = 'GET' === $method || in_array($path, ['/api/v2/stop-list/add', '/api/v2/stop-list/remove'], true);
        if (!$queryOnly && isset($data['headers']) && is_array($data['headers'])) {
            $data['headers'] = (object) $data['headers'];
        }
        $options[$queryOnly ? 'query' : 'json'] = $data;
        try {
            $response = $this->client->request($method, 'https://'.$this->host.$path, $options);
            $status = $response->getStatusCode();
            $result = $response->toArray(false);
        } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface $e) {
            // An interrupted request might already have been accepted by the provider.
            throw new ApiException('Mailganer response unavailable; check submission status before retrying.', false);
        }
        if ($status < 200 || $status >= 300 || 'ok' !== strtolower((string) ($result['status'] ?? ''))) {
            $message = is_scalar($result['message'] ?? null) ? (string) $result['message'] : 'Invalid provider response';
            $message = substr(str_replace($this->key, '[redacted]', $message), 0, 500);
            $definitive = ($status >= 400 && $status < 500) || ($status >= 200 && $status < 300 && in_array(strtolower((string) ($result['status'] ?? '')), ['error', 'throttling'], true));
            throw new ApiException(sprintf('Mailganer HTTP %d: %s', $status, $message), $definitive);
        }

        return $result;
    }
}
