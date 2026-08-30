<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerBundle\Mailer\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class MailganerApiTransport extends AbstractApiTransport
{
    private const HOST = 'api.samotpravil.ru';
    private const ENDPOINT = '/api/v2/mail/send';

    public function __construct(
        #[\SensitiveParameter] private string $apiKey,
        private bool $trackOpen = false,
        private bool $trackClick = false,
        private bool $checkLocalStopList = true,
        private bool $raw = true,
        private ?string $trackDomain = null,
        private ?string $xTrackPrefix = null,
        ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($client, $dispatcher, $logger);
    }

    public function __toString(): string
    {
        return sprintf('mailganer+api://%s', $this->getEndpoint());
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $payload = $this->buildPayload($email, $envelope, $sentMessage);

        $response = $this->client->request('POST', 'https://'.$this->getEndpoint().self::ENDPOINT, [
            'headers' => [
                'Authorization' => $this->apiKey,
                'Content-Type'  => 'application/json',
            ],
            'json' => $payload,
        ]);

        try {
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $exception) {
            throw new HttpTransportException('Could not reach the remote Mailganer API server.', $response, 0, $exception);
        }

        $result = $this->decodeResponse($response);

        if (!$this->isSuccessfulResponse($statusCode, $result)) {
            throw new HttpTransportException($this->buildErrorMessage($statusCode, $response, $result), $response);
        }

        if (is_array($result) && isset($result['message_id']) && is_scalar($result['message_id'])) {
            $sentMessage->setMessageId((string) $result['message_id']);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Email $email, Envelope $envelope, SentMessage $sentMessage): array
    {
        $recipients = $this->getRecipients($email, $envelope);
        if ([] === $recipients) {
            throw new TransportException('Unable to send email: recipient list is empty.');
        }

        $sender = $envelope->getSender();
        $recipient = $recipients[0];
        $body = $this->resolveMessageBody($email);
        $xTrackId = $this->resolveXTrackId($email, $sentMessage, $recipient);
        $headers = $this->collectCustomHeaders($email, $xTrackId);

        $payload = [
            'email_from'            => $this->stringifyAddress($sender),
            'email_to'              => $recipient->getAddress(),
            'subject'               => (string) ($email->getSubject() ?? ''),
            'message_text'          => $body,
            'x_track_id'            => $xTrackId,
            'check_local_stop_list' => $this->checkLocalStopList,
            'track_open'            => $this->trackOpen,
            'track_click'           => $this->trackClick,
            'raw'                   => $this->raw,
        ];

        if (null !== $this->trackDomain && '' !== trim($this->trackDomain)) {
            $payload['track_domain'] = trim($this->trackDomain);
        }

        if ([] !== $headers) {
            $payload['headers'] = $headers;
        }

        $attachments = $this->collectAttachments($email);
        if ([] !== $attachments) {
            $payload['attach_files'] = $attachments;
        }

        return $payload;
    }

    private function resolveMessageBody(Email $email): string
    {
        $html = $email->getHtmlBody();
        if (null !== $html && '' !== $html) {
            return $html;
        }

        $text = $email->getTextBody();
        if (null !== $text && '' !== $text) {
            return nl2br($text, false);
        }

        throw new TransportException('Unable to send email: both HTML and text bodies are empty.');
    }

    private function resolveXTrackId(Email $email, SentMessage $sentMessage, Address $recipient): string
    {
        if ($email->getHeaders()->has('X-Track-ID')) {
            $headerValue = trim((string) $email->getHeaders()->get('X-Track-ID')->getBodyAsString());
            if ('' !== $headerValue) {
                return $headerValue;
            }
        }

        $prefix = null !== $this->xTrackPrefix && '' !== trim($this->xTrackPrefix)
            ? trim($this->xTrackPrefix)
            : 'mautic';

        $fingerprint = hash(
            'sha256',
            $recipient->getAddress().'|'.$sentMessage->getMessageId().'|'.microtime(true)
        );

        return sprintf('%s-%s-%s', $prefix, gmdate('YmdHis'), substr($fingerprint, 0, 14));
    }

    /**
     * @return array<string, string>
     */
    private function collectCustomHeaders(Email $email, string $xTrackId): array
    {
        $headers = [
            'X-Track-ID' => $xTrackId,
        ];

        $reservedHeaders = [
            'from',
            'to',
            'cc',
            'bcc',
            'subject',
            'reply-to',
            'return-path',
            'message-id',
            'mime-version',
            'content-type',
            'date',
        ];

        foreach ($email->getHeaders()->all() as $name => $header) {
            if (in_array(strtolower($name), $reservedHeaders, true)) {
                continue;
            }

            $value = trim((string) $header->getBodyAsString());
            if ('' === $value) {
                continue;
            }

            $headers[$header->getName()] = $value;
        }

        return $headers;
    }

    /**
     * @return array<int, array{name: string, filebody: string}>
     */
    private function collectAttachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $index => $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename');
            if (!is_string($filename) || '' === $filename) {
                $filename = sprintf('attachment-%d', $index + 1);
            }

            $content = str_replace(["\r", "\n"], '', $attachment->bodyToString());
            if ('' === $content) {
                continue;
            }

            $attachments[] = [
                'name'     => $filename,
                'filebody' => $content,
            ];
        }

        return $attachments;
    }

    private function stringifyAddress(Address $address): string
    {
        $name = trim($address->getName());
        if ('' === $name) {
            return $address->getAddress();
        }

        return sprintf('%s <%s>', $name, $address->getAddress());
    }

    private function getEndpoint(): string
    {
        $host = $this->host ?: self::HOST;

        return $host.($this->port ? ':'.$this->port : '');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeResponse(ResponseInterface $response): ?array
    {
        try {
            $decoded = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed>|null $result
     */
    private function isSuccessfulResponse(int $statusCode, ?array $result): bool
    {
        if ($statusCode >= 400 || null === $result) {
            return false;
        }

        return 'ok' === strtolower((string) ($result['status'] ?? ''));
    }

    /**
     * @param array<string, mixed>|null $result
     */
    private function buildErrorMessage(int $statusCode, ResponseInterface $response, ?array $result): string
    {
        $details = null;

        if (is_array($result)) {
            $parts = [];

            if (isset($result['code']) && is_scalar($result['code'])) {
                $parts[] = 'provider_code='.(string) $result['code'];
            }

            if (isset($result['message']) && is_scalar($result['message'])) {
                $parts[] = (string) $result['message'];
            }

            if ([] !== $parts) {
                $details = implode('; ', $parts);
            }
        }

        if (null === $details) {
            $details = trim($response->getContent(false));
        }

        if ('' === $details) {
            $details = 'Unknown Mailganer API response.';
        }

        return sprintf('Unable to send an email via Mailganer API: %s (HTTP %d).', $details, $statusCode);
    }
}
