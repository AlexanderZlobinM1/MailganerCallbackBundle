<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerBundle\Mailer;

use MauticPlugin\MailganerBundle\Api\ApiException;
use Symfony\Component\Mailer\Exception\TransportException;

/** Durable receipts, not a second delivery queue. Mautic remains responsible for retries. */
final class SubmissionStore
{
    public function __construct(private string $directory)
    {
    }

    public function submit(string $id, callable $send, ?callable $recover = null): array
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new TransportException('Cannot create Mailganer receipt directory.');
        }
        $path = $this->directory.'/'.hash('sha256', $id).'.json';
        $file = @fopen($path.'.lock', 'c+');
        if (false === $file) {
            throw new TransportException('Cannot open Mailganer receipt.');
        }
        @chmod($path.'.lock', 0600);
        try {
            if (!flock($file, LOCK_EX)) {
                throw new TransportException('Cannot lock Mailganer receipt.');
            }
            $record = [];
            if (is_file($path)) {
                $raw = file_get_contents($path);
                $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($record) || !isset($record['state'])) {
                    throw new TransportException('Invalid Mailganer receipt; sending stopped.');
                }
            }
            if ('accepted' === ($record['state'] ?? null)) {
                return $record['result'];
            }
            if ('pending' === ($record['state'] ?? null)) {
                $result = null !== $recover ? $recover() : null;
                if (null === $result) {
                    throw new TransportException('Mailganer submission '.$id.' has an uncertain outcome. Reconcile it before retrying; no duplicate was sent.');
                }
                $this->write($path, ['state' => 'accepted', 'result' => $result, 'updated_at' => time()]);

                return $result;
            }
            $this->write($path, ['state' => 'pending', 'updated_at' => time()]);
            try {
                $result = $send();
            } catch (ApiException $e) {
                if ($e->definitive) {
                    $this->write($path, ['state' => 'rejected', 'updated_at' => time()]);
                }
                throw $e;
            }
            $this->write($path, ['state' => 'accepted', 'result' => $result, 'updated_at' => time()]);

            return $result;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    private function write(string $path, array $record): void
    {
        // Never truncate the previous pending receipt after a remote acceptance.
        $json = json_encode($record, JSON_THROW_ON_ERROR);
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $file = @fopen($temporary, 'x');
        if (false === $file) {
            throw new TransportException('Cannot create Mailganer receipt snapshot.');
        }
        @chmod($temporary, 0600);
        try {
            if (strlen($json) !== fwrite($file, $json) || !fflush($file)
                || (function_exists('fsync') && !fsync($file))) {
                throw new TransportException('Cannot persist Mailganer receipt; sending stopped.');
            }
            if (!rename($temporary, $path)) {
                throw new TransportException('Cannot replace Mailganer receipt.');
            }
        } finally {
            fclose($file);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
