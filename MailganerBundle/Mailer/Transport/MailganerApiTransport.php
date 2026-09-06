<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerBundle\Mailer\Transport;

use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use Mautic\EmailBundle\Mailer\Transport\TokenTransportInterface;
use Mautic\EmailBundle\Mailer\Transport\TokenTransportTrait;
use MauticPlugin\MailganerBundle\Api\ApiException;
use MauticPlugin\MailganerBundle\Api\MailganerApi;
use MauticPlugin\MailganerBundle\Mailer\SubmissionStore;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MailganerApiTransport extends AbstractTransport implements TokenTransportInterface
{
    use TokenTransportTrait;

    private MailganerApi $api;
    private ?SubmissionStore $store;
    private string $host = 'api.samotpravil.ru';
    private array $lastSubmissions = [];

    public function __construct(
        #[\SensitiveParameter] private string $apiKey,
        private bool $trackOpen = false,
        private bool $trackClick = false,
        private bool $checkLocalStopList = true,
        private bool $raw = true,
        private ?string $trackDomain = null,
        private ?string $xTrackPrefix = null,
        private ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
        private int $batchSize = 100,
        ?string $journalDirectory = null,
        private bool $forcePackage = false,
    ) {
        parent::__construct($dispatcher, $logger);
        $this->client ??= HttpClient::create();
        $this->api = new MailganerApi($this->client, $apiKey);
        $this->store = null !== $journalDirectory ? new SubmissionStore($journalDirectory.'/'.hash('sha256', $apiKey)) : null;
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new TransportException('Mailganer batch_size must be between 1 and 1000.');
        }
    }

    public function setHost(?string $host): static
    {
        $this->host = $host ?: 'api.samotpravil.ru';
        $this->api = new MailganerApi($this->client, $this->apiKey, $this->host);

        return $this;
    }

    public function setPort(?int $port): static
    {
        if (null !== $port && 443 !== $port) {
            throw new TransportException('Mailganer API uses HTTPS port 443.');
        }

        return $this;
    }

    public function __toString(): string
    {
        return 'mailganer+api://'.$this->host;
    }

    public function getMaxBatchLimit(): int
    {
        return $this->batchSize;
    }

    public function getLastSubmissions(): array
    {
        return $this->lastSubmissions;
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();
        if (!$email instanceof Email) {
            throw new TransportException('Mailganer requires a MIME Email.');
        }
        $this->lastSubmissions = [];
        $rows = $this->personalize($email, $message);
        // Prepare every payload before sending anything: malformed recipients or unsupported MIME fail atomically.
        $groups = [];
        foreach ($rows as $row) {
            $payload = $row['payload'];
            $common = $payload;
            unset($common['email_to'], $common['subject'], $common['message_text'], $common['params'], $common['x_track_id']);
            unset($common['headers']['X-Track-ID']);
            // JSON packages do not document text/plain or attachments; use the single API without losing them.
            $canBatch = $row['batch'] && !isset($payload['attach_files']) && !isset($payload['message_text_plain']);
            $groupKey = $canBatch ? hash('sha256', json_encode($common, JSON_THROW_ON_ERROR)) : $row['id'];
            $groups[$groupKey][] = $row;
        }
        foreach ($groups as $group) {
            foreach (array_chunk($group, $this->batchSize) as $chunk) {
                if (count($chunk) > 1 || ($this->forcePackage && $chunk[0]['batch'] && !isset($chunk[0]['payload']['attach_files']) && !isset($chunk[0]['payload']['message_text_plain']))) {
                    $this->sendPackage($chunk);
                } else {
                    $row = $chunk[0];
                    $result = $this->submit($row['id'], function () use ($row): array {
                        $result = $this->api->request('POST', '/api/v1/smtp_send', $row['payload']);
                        if (empty($result['message_id']) || !is_scalar($result['message_id'])) {
                            throw new ApiException('Mailganer accepted response lacks message_id; reconcile before retrying.', false);
                        }

                        return ['message_id' => (string) $result['message_id']];
                    });
                    $this->lastSubmissions[] = ['x_track_id' => $row['id']] + $result;
                    if (1 === count($rows)) {
                        $message->setMessageId($result['message_id']);
                    }
                }
            }
        }
    }

    private function sendPackage(array $rows): void
    {
        $first = $rows[0];
        $p = $first['payload'];
        $id = 'mtc-e'.$first['emailId'].'-p'.substr(hash('sha256', implode('|', array_column($rows, 'id'))), 0, 40);
        $from = Address::create($p['email_from']);
        $package = [
            'email_from' => $from->getAddress(), 'name_from' => $from->getName(),
            'subject' => '{{ mtc_subject }}', 'message_text' => '{{ mtc_html|safe }}',
            'headers' => $p['headers'], 'check_local_stop_list' => $this->checkLocalStopList,
            'track_open' => $this->trackOpen, 'track_click' => $this->trackClick,
            'external_id' => $id, 'users' => [],
        ];
        unset($package['headers']['X-Track-ID']);
        if (null !== $this->trackDomain) {
            $package['track_domain'] = $this->trackDomain;
        }
        foreach ($rows as $row) {
            $package['users'][] = ['emailto' => $row['payload']['email_to'], 'mtc_subject' => $row['subject'], 'mtc_html' => $row['html']];
        }
        $result = $this->submit($id, function () use ($package): array {
            $result = $this->api->request('POST', '/api/v1/add_json_package', $package);
            $value = $result['message'] ?? null;
            if (!is_array($value) || empty($value['pack_id']) || !in_array($value['status'] ?? '', ['S004', 'S005', 'S009', 'S011', 'S018', 'S020', 'S021'], true)) {
                throw new ApiException('Mailganer did not confirm package acceptance; reconcile its external_id before retrying.', false);
            }

            return ['pack_id' => (string) $value['pack_id'], 'status' => $value['status']];
        }, function () use ($id): ?array {
            $result = $this->api->request('GET', '/api/v2/package/status', ['external_id' => $id]);
            $item = $result['items'][0] ?? [];
            if (1 === count($result['items'] ?? []) && !empty($item['issuen'])) {
                return ['pack_id' => (string) $item['issuen'], 'status' => $item['status_code'] ?? 'unknown'];
            }

            return null;
        });
        $this->lastSubmissions[] = ['external_id' => $id] + $result;
        if (in_array($result['status'] ?? '', ['S007', 'S013', 'S022'], true)) {
            throw new TransportException('Mailganer package '.$id.' ended with '.$result['status'].'. Inspect package statistics before taking further action.');
        }
        $this->getLogger()->info('Mailganer package accepted.', ['external_id' => $id, 'pack_id' => $result['pack_id'], 'recipients' => count($rows)]);
    }

    private function submit(string $id, callable $send, ?callable $recover = null): array
    {
        return null !== $this->store ? $this->store->submit($id, $send, $recover) : $send();
    }

    private function personalize(Email $email, SentMessage $sent): array
    {
        $metadata = $email instanceof MauticMessage ? $email->getMetadata() : [];
        if ([] !== $metadata && ($email->getCc() || $email->getBcc())) {
            throw new TransportException('Tokenized Mailganer batches cannot contain CC/BCC recipients.');
        }
        if ([] === $metadata && count($sent->getEnvelope()->getRecipients()) !== 1) {
            throw new TransportException('Use separate recipient messages or Mautic token batches; the single Mailganer API accepts one recipient.');
        }
        $rows = [];
        foreach ([] !== $metadata ? $metadata : [$sent->getEnvelope()->getRecipients()[0]->getAddress() => []] as $recipient => $data) {
            $copy = clone $email;
            if ($copy instanceof MauticMessage) {
                $copy->clearMetadata();
                $copy->updateLeadIdHash($data['hashId'] ?? $copy->getLeadIdHash());
                $tokens = $data['tokens'] ?? [];
                MailHelper::searchReplaceTokens(array_keys($tokens), array_values($tokens), $copy);
                if (!empty($tokens['{listUnsubscribeHeader}'])) {
                    $copy->getHeaders()->remove('List-Unsubscribe');
                    $copy->getHeaders()->addTextHeader('List-Unsubscribe', $tokens['{listUnsubscribeHeader}']);
                    if (!$copy->getHeaders()->has('List-Unsubscribe-Post')) {
                        $copy->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                    }
                }
            }
            $copy->to(new Address($recipient, (string) ($data['name'] ?? '')));
            $emailId = filter_var($data['emailId'] ?? $copy->getHeaders()->get('X-EMAIL-ID')?->getBodyAsString(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
            $html = $copy->getHtmlBody();
            $text = $copy->getTextBody();
            if (null === $html) {
                $html = nl2br(htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
            }
            if ('' === $html) {
                throw new TransportException('Mailganer email body is empty.');
            }
            $headers = [];
            foreach ($copy->getHeaders()->all() as $name => $header) {
                if (in_array(strtolower($name), ['from', 'to', 'cc', 'bcc', 'subject', 'return-path', 'message-id', 'mime-version', 'content-type', 'content-transfer-encoding', 'date', 'x-track-id'], true)) {
                    continue;
                }
                $headers[$header->getName()] = $header->getBodyAsString();
            }
            if ($emailId) {
                $headers['X-EMAIL-ID'] = (string) $emailId;
            }
            $subject = (string) $copy->getSubject();
            $payload = [
                'email_from' => ($copy->getFrom()[0] ?? $sent->getEnvelope()->getSender())->toString(),
                'email_to' => $recipient, 'subject' => $subject,
                'message_text' => $html, 'params' => new \stdClass(), 'raw' => $this->raw,
                'headers' => $headers, 'track_open' => $this->trackOpen, 'track_click' => $this->trackClick,
                'check_local_stop_list' => $this->checkLocalStopList,
            ];
            if (null !== $text) {
                $payload['message_text_plain'] = $text;
            }
            if (null !== $this->trackDomain) {
                $payload['track_domain'] = $this->trackDomain;
            }
            foreach ($copy->getAttachments() as $part) {
                if ('inline' === $part->getPreparedHeaders()->getHeaderBody('Content-Disposition')) {
                    throw new TransportException('Inline CID attachments require the Mailganer XML package path; refusing to silently corrupt this email.');
                }
                $h = $part->getPreparedHeaders();
                $name = $h->getHeaderParameter('Content-Disposition', 'filename') ?: 'attachment';
                $body = $part->getBody();
                if (is_resource($body)) {
                    rewind($body);
                    $body = stream_get_contents($body);
                }
                $payload['attach_files'][] = ['name' => $name, 'filebody' => base64_encode((string) $body)];
            }
            $stable = $data['hashId'] ?? ($copy instanceof MauticMessage ? $copy->getLeadIdHash() : null);
            $identity = $stable ?: $sent->getMessageId();
            $id = 'mtc-e'.$emailId.'-h'.substr(hash('sha256', $identity.'|'.json_encode($payload, JSON_THROW_ON_ERROR)), 0, 40);
            $payload['x_track_id'] = $id;
            $rows[] = ['id' => $id, 'emailId' => $emailId, 'payload' => $payload, 'html' => $html, 'subject' => $subject, 'batch' => [] !== $metadata];
        }

        return $rows;
    }
}
