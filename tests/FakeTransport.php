<?php

declare(strict_types=1);

namespace Recodive\Hansho\Tests;

use Recodive\Hansho\Hansho;
use Recodive\Hansho\Http\Transport;
use Recodive\Hansho\Http\TransportResponse;

/** Records requests and answers from a handler: (request, call index) -> TransportResponse, or throws. */
final class FakeTransport implements Transport
{
    /** @var list<array{method:string,url:string,path:string,headers:array<string,string>,body:?string}> */
    public array $requests = [];
    /** @var list<int> */
    public array $sleeps = [];
    public const KEY = 'ck_test_key_123';

    /** @param \Closure(array<string,mixed>, int): TransportResponse $handler */
    public function __construct(private readonly \Closure $handler)
    {
    }

    public static function reply(int $status, mixed $json = null, array $headers = [], ?string $raw = null): TransportResponse
    {
        $body = $raw ?? ($json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR));

        return new TransportResponse($status, array_change_key_case($headers, CASE_LOWER), $body);
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): TransportResponse
    {
        $path = substr($url, strlen('http://hub.test'));
        $req = ['method' => $method, 'url' => $url, 'path' => $path, 'headers' => array_change_key_case($headers, CASE_LOWER), 'body' => $body];
        $this->requests[] = $req;

        return ($this->handler)($req, count($this->requests) - 1);
    }

    /** @return array{0:Hansho,1:self} */
    public static function client(\Closure $handler, int $maxRetries = 3): array
    {
        $t = new self($handler);
        $c = new Hansho('http://hub.test/', self::KEY, maxRetries: $maxRetries, transport: $t, sleep: function (int $ms) use ($t): void {
            $t->sleeps[] = $ms;
        });

        return [$c, $t];
    }
}
