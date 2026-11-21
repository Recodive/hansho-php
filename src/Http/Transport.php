<?php

declare(strict_types=1);

namespace Recodive\Hansho\Http;

/** Performs one HTTP request. Implement it to use a proxy, another HTTP library, or a test double. */
interface Transport
{
    /**
     * @param array<string,string> $headers
     * @param float                $timeoutSeconds 0 disables the timeout
     *
     * @throws \Throwable when no answer arrives (connection failure, timeout); it is wrapped in a NetworkException
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): TransportResponse;
}
