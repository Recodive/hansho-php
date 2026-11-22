<?php

declare(strict_types=1);

namespace Recodive\Hansho\Exception;

/** A non-2xx answer from the hub. The API key never appears in it. */
final class ApiException extends HanshoException
{
    /** The user is outside the channel's audience (403, startActivity() for such a user). */
    public const NO_ACCESS = 'no_access';
    /** The group does not exist (404, getGroup() and deleteGroup()). */
    public const UNKNOWN_GROUP = 'unknown_group';

    /**
     * @param string      $apiCode        the hub's error code, e.g. "invalid_request"; "" when the body was not JSON
     * @param string|null $field          the offending request field for validation failures
     * @param int|null    $retryAfterMs   parsed Retry-After in milliseconds
     * @param string|null $detail         the hub's validation message, when present
     */
    public function __construct(
        string $method,
        string $path,
        public readonly int $status,
        public readonly string $apiCode = '',
        public readonly ?string $field = null,
        public readonly ?int $retryAfterMs = null,
        public readonly ?string $detail = null,
    ) {
        $text = "hansho: $method $path: $status";
        if ($apiCode !== '') {
            $text .= " $apiCode";
        }
        if ($field !== null) {
            $text .= " (field $field)";
        }
        if ($retryAfterMs !== null) {
            $text .= " (retry after $retryAfterMs ms)";
        }
        parent::__construct($text, $status);
    }
}
