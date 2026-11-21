<?php

declare(strict_types=1);

namespace Recodive\Hansho;

/** @internal */
final class Retry
{
    public const DEFAULT_MAX_RETRIES = 3;
    private const BASE_BACKOFF_MS = 250;
    private const MAX_BACKOFF_MS = 8000;
    /** A longer Retry-After is thrown to the caller instead of waited out. */
    public const MAX_WAIT_MS = 60_000;

    public static function isIdempotent(string $method): bool
    {
        return $method === 'GET' || $method === 'PUT' || $method === 'DELETE';
    }

    public static function isRetryableStatus(int $status): bool
    {
        return $status === 429 || $status === 503;
    }

    /** Exponential backoff with jitter in [d/2, d]. */
    public static function backoffMs(int $attempt): int
    {
        $d = min(self::BASE_BACKOFF_MS * (2 ** min($attempt, 10)), self::MAX_BACKOFF_MS);
        $half = intdiv($d, 2);

        return $half + random_int(0, $d - $half);
    }

    /** Reads delay-seconds or an HTTP date; 0 when absent or unusable. */
    public static function parseRetryAfter(?string $value, ?int $now = null): int
    {
        if ($value === null) {
            return 0;
        }
        $v = trim($value);
        if ($v === '') {
            return 0;
        }
        if (preg_match('/^\d+(\.\d+)?$/D', $v) === 1) {
            return (int) round((float) $v * 1000);
        }
        $at = strtotime($v);
        if ($at === false) {
            return 0;
        }

        return max(0, ($at - ($now ?? time())) * 1000);
    }
}
