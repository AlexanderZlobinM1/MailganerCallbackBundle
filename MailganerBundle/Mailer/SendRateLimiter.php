<?php

declare(strict_types=1);

namespace MauticPlugin\MailganerBundle\Mailer;

use Symfony\Component\Mailer\Exception\TransportException;

/** Shared token bucket: one rate per installation/account, including multiple workers. */
final class SendRateLimiter
{
    private \Closure $clock;
    private \Closure $sleep;

    public function __construct(private string $directory, ?\Closure $clock = null, ?\Closure $sleep = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sleep = $sleep ?? static fn (float $seconds) => usleep((int) ($seconds * 1000000));
    }

    /** Re-read saved controls while waiting; return the permitted next wave size. */
    public function acquire(int $remaining, callable $settings): int
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new TransportException('Cannot create Mailganer rate directory.');
        }
        while (true) {
            $limits = SendingControl::validate($settings());
            if (0 === $limits['rate']) {
                throw new TransportException('Mailganer delivery is paused (rate=0); no new requests started.');
            }

            $path = $this->directory.'/rate.json';
            $lock = @fopen($path.'.lock', 'c+');
            if (false === $lock) {
                throw new TransportException('Cannot open Mailganer rate lock.');
            }
            @chmod($path.'.lock', 0600);
            try {
                if (!flock($lock, LOCK_EX)) {
                    throw new TransportException('Cannot lock Mailganer rate state.');
                }
                $now = ($this->clock)();
                $state = is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : ['tokens' => $limits['rate'] ?? 10, 'updated' => $now];
                if (!is_array($state) || !is_numeric($state['tokens'] ?? null) || !is_numeric($state['updated'] ?? null)) {
                    throw new TransportException('Invalid Mailganer rate state; sending stopped.');
                }
                $rate = $limits['rate'] ?? ($state['auto_rate'] ?? 10);
                $count = min($remaining, $limits['concurrency'], $rate);
                $tokens = min($rate, $state['tokens'] + max(0, $now - $state['updated']) * $rate);
                $blocked = max(0, ($state['blocked_until'] ?? 0) - $now);
                if ($tokens >= $count && 0 == $blocked) {
                    $state['tokens'] = $tokens - $count;
                    $state['updated'] = $now;
                    $state['effective_rate'] = $rate;
                    $state['mode'] = null === $limits['rate'] ? 'adaptive' : 'manual';
                    $this->write($path, $state);

                    return $count;
                }
                $wait = min(0.25, max(0.001, $blocked, ($count - $tokens) / $rate));
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            ($this->sleep)($wait);
        }
    }

    public function feedback(bool $throttled, ?int $retryAfter = null): void
    {
        $path = $this->directory.'/rate.json';
        $lock = @fopen($path.'.lock', 'c+');
        if (false === $lock) {
            throw new TransportException('Cannot open Mailganer rate feedback lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new TransportException('Cannot lock Mailganer rate feedback.');
            }
            $state = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($state) || !isset($state['tokens'], $state['updated'])) {
                throw new TransportException('Invalid Mailganer rate feedback state.');
            }
            $now = ($this->clock)();
            $rate = (int) ($state['auto_rate'] ?? 10);
            if ($throttled) {
                $state['auto_rate'] = max(1, (int) floor($rate / 2));
                $state['blocked_until'] = max($state['blocked_until'] ?? 0, $now + max(1, $retryAfter ?? 1));
                $state['successes'] = 0;
                $state['tokens'] = 0;
                $state['updated'] = $now;
                $state['last_increase'] = $now;
            } elseif (($state['blocked_until'] ?? 0) <= $now) {
                $state['successes'] = ($state['successes'] ?? 0) + 1;
                if ($state['successes'] >= 20 && $now - ($state['last_increase'] ?? 0) >= 1) {
                    $state['auto_rate'] = min(1000, $rate + max(1, (int) floor($rate * 0.1)));
                    $state['successes'] = 0;
                    $state['last_increase'] = $now;
                }
            }
            $this->write($path, $state);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Current ceiling, not an observed throughput or a quota reported by the API. */
    public function status(array $limits): array
    {
        $limits = SendingControl::validate($limits);
        $path = $this->directory.'/rate.json';
        $state = is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
        if (!is_array($state)) {
            throw new TransportException('Invalid Mailganer rate state.');
        }

        return [
            'mode' => 0 === $limits['rate'] ? 'paused' : (null === $limits['rate'] ? 'adaptive' : 'manual'),
            'rate' => $limits['rate'],
            'effective_rate' => $limits['rate'] ?? ($state['auto_rate'] ?? 10),
            'concurrency' => $limits['concurrency'],
            'backoff_seconds' => max(0, (int) ceil(($state['blocked_until'] ?? 0) - ($this->clock)())),
        ];
    }

    private function write(string $path, array $state): void
    {
        $temp = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        $file = @fopen($temp, 'x');
        if (false === $file) {
            throw new TransportException('Cannot create Mailganer rate snapshot.');
        }
        @chmod($temp, 0600);
        try {
            if (strlen($json) !== fwrite($file, $json) || !fflush($file) || (function_exists('fsync') && !fsync($file)) || !rename($temp, $path)) {
                throw new TransportException('Cannot persist Mailganer rate state.');
            }
        } finally {
            fclose($file);
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }
}
