<?php

declare(strict_types=1);

namespace Recodive\Hansho\Http;

/** The default transport, on ext-curl. Does not follow redirects. */
final class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): TransportResponse
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        $lines = ['Expect:'];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }
        $responseHeaders = [];
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutSeconds > 0 ? (int) ($timeoutSeconds * 1000) : 0,
            CURLOPT_TIMEOUT_MS => $timeoutSeconds > 0 ? (int) ($timeoutSeconds * 1000) : 0,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        if ($out === false) {
            $err = curl_error($ch);
            throw new \RuntimeException($err !== '' ? $err : 'request failed');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return new TransportResponse($status, $responseHeaders, (string) $out);
    }
}
