<?php

declare(strict_types=1);

namespace Recodive\Hansho\Exception;

/** No HTTP answer arrived: connection failure, timeout, or an unreadable response. See {@see getPrevious()}. */
final class NetworkException extends HanshoException
{
    public function __construct(string $method, string $path, string $reason, ?\Throwable $previous = null)
    {
        parent::__construct("hansho: $method $path: $reason", 0, $previous);
    }
}
