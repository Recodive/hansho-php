<?php

declare(strict_types=1);

namespace Recodive\Hansho\Http;

final class TransportResponse
{
    /** @param array<string,string> $headers header names in lower case */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }
}
