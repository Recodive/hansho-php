<?php

declare(strict_types=1);

namespace Recodive\Hansho\Tests;

use PHPUnit\Framework\TestCase;
use Recodive\Hansho\ActivityUpdate;
use Recodive\Hansho\Channel;
use Recodive\Hansho\Exception\ApiException;
use Recodive\Hansho\Exception\HanshoException;
use Recodive\Hansho\Exception\NetworkException;
use Recodive\Hansho\Hansho;
use Recodive\Hansho\Http\Transport;
use Recodive\Hansho\Message;
use Recodive\Hansho\Retry;

final class ClientTest extends TestCase
{
    private static function msg(?string $key = null): Message
    {
        return new Message('u', 'c', 't', 'b', idempotencyKey: $key);
    }

    public function testGetRetriesOn429HonouringRetryAfter(): void
    {
        [$c, $t] = FakeTransport::client(fn ($r, $i) => $i < 2
            ? FakeTransport::reply(429, ['error' => 'rate_limited'], ['Retry-After' => '7'])
            : FakeTransport::reply(200, ['id' => 'ops', 'name' => 'Ops', 'default_level' => 'active', 'max_level' => 'page']));
        $ch = $c->getChannel('ops');
        $this->assertSame('Ops', $ch->name);
        $this->assertCount(3, $t->requests);
        $this->assertSame([7000, 7000], $t->sleeps);
    }

    public function testRetriesStopAfterThreeAndExhaustedErrorIsThrown(): void
    {
        [$c, $t] = FakeTransport::client(fn () => FakeTransport::reply(503, ['error' => 'unavailable']));
        try {
            $c->getChannel('ops');
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(503, $e->status);
            $this->assertSame('unavailable', $e->apiCode);
        }
        $this->assertCount(4, $t->requests, '1 try + 3 retries');
        $this->assertCount(3, $t->sleeps);
        foreach ($t->sleeps as $attempt => $ms) {
            $cap = min(250 * (2 ** $attempt), 8000);
            $this->assertGreaterThanOrEqual($cap / 2, $ms);
            $this->assertLessThanOrEqual($cap, $ms);
        }
    }

    public function testMaxRetriesZeroDisablesRetries(): void
    {
        [$c, $t] = FakeTransport::client(fn () => FakeTransport::reply(503, ['error' => 'x']), maxRetries: 0);
        $this->expectException(ApiException::class);
        try {
            $c->deleteChannel('ops');
        } finally {
            $this->assertCount(1, $t->requests);
        }
    }

    public function testPutAndDeleteAreRetried(): void
    {
        foreach ([fn (Hansho $c) => $c->putChannel('ops', new Channel('n', 'active', 'page')), fn (Hansho $c) => $c->deleteChannel('ops'), fn (Hansho $c) => $c->putTemplate('t', 1, [])] as $call) {
            [$c, $t] = FakeTransport::client(fn ($r, $i) => $i === 0 ? FakeTransport::reply(429, null, ['Retry-After' => '1']) : FakeTransport::reply(200, []));
            $call($c);
            $this->assertCount(2, $t->requests);
            $this->assertSame([1000], $t->sleeps);
        }
    }

    public function testOtherStatusesAreNotRetried(): void
    {
        foreach ([400, 401, 404, 409, 500, 502] as $status) {
            [$c, $t] = FakeTransport::client(fn () => FakeTransport::reply($status, ['error' => 'x']));
            try {
                $c->getMessage('m');
                $this->fail('expected ApiException');
            } catch (ApiException) {
            }
            $this->assertCount(1, $t->requests, "status $status");
        }
    }

    public function testPostMessageIsNeverRetriedWithoutKey(): void
    {
        foreach ([429, 503] as $status) {
            [$c, $t] = FakeTransport::client(fn () => FakeTransport::reply($status, ['error' => 'x'], ['Retry-After' => '1']));
            try {
                $c->sendMessage(self::msg());
                $this->fail('expected ApiException');
            } catch (ApiException) {
            }
            $this->assertCount(1, $t->requests);
            $this->assertSame([], $t->sleeps);
        }
    }

    public function testPostMessageWithIdempotencyKeyIsRetriedAndKeySentAsHeaderOnly(): void
    {
        [$c, $t] = FakeTransport::client(fn ($r, $i) => $i < 2
            ? FakeTransport::reply(503, ['error' => 'unavailable'])
            : FakeTransport::reply(202, ['message_id' => 'msg_1', 'level' => 'active', 'devices' => 1, 'status' => 'queued', 'deliveries' => []]));
        $res = $c->sendMessage(self::msg('key-1'));
        $this->assertSame('msg_1', $res->messageId);
        $this->assertCount(3, $t->requests);
        foreach ($t->requests as $r) {
            $this->assertSame('key-1', $r['headers']['idempotency-key']);
            $this->assertStringNotContainsString('key-1', (string) $r['body']);
            $this->assertStringNotContainsString('idempotency', strtolower((string) $r['body']));
        }
    }

    public function testNoIdempotencyHeaderWithoutKey(): void
    {
        [$c, $t] = FakeTransport::client(fn () => FakeTransport::reply(202, ['message_id' => 'm']));
        $c->sendMessage(self::msg());
        $this->assertArrayNotHasKey('idempotency-key', $t->requests[0]['headers']);
    }

    public function testPatchIsNeverRetried(): void
    {
        [$c, $t] = FakeTransport::client(fn () => FakeTransport::reply(429, ['error' => 'rate_limited'], ['Retry-After' => '1']));
        try {
            $c->updateActivity('act_1', new ActivityUpdate(['p' => 1]));
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(429, $e->status);
            $this->assertSame(1000, $e->retryAfterMs);
        }
        $this->assertCount(1, $t->requests);
    }

    public function testRetryAfterOverSixtySecondsIsThrownNotWaited(): void
    {
        [$c, $t] = FakeTransport::client(fn () => FakeTransport::reply(429, ['error' => 'rate_limited'], ['Retry-After' => '61']));
        try {
            $c->getChannel('ops');
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(61_000, $e->retryAfterMs);
        }
        $this->assertCount(1, $t->requests);
        $this->assertSame([], $t->sleeps);
    }

    public function testRetryAfterOfExactlySixtySecondsIsWaited(): void
    {
        [$c, $t] = FakeTransport::client(fn ($r, $i) => $i === 0 ? FakeTransport::reply(429, null, ['Retry-After' => '60']) : FakeTransport::reply(200, []));
        $c->getChannel('ops');
        $this->assertSame([60_000], $t->sleeps);
    }

    public function testParseRetryAfter(): void
    {
        $this->assertSame(0, Retry::parseRetryAfter(null));
        $this->assertSame(0, Retry::parseRetryAfter(''));
        $this->assertSame(0, Retry::parseRetryAfter('soon'));
        $this->assertSame(2500, Retry::parseRetryAfter('2.5'));
        $this->assertSame(10_000, Retry::parseRetryAfter('Wed, 21 Oct 2015 07:28:10 GMT', strtotime('Wed, 21 Oct 2015 07:28:00 GMT')));
        $this->assertSame(0, Retry::parseRetryAfter('Wed, 21 Oct 2015 07:28:00 GMT', strtotime('Wed, 21 Oct 2015 08:00:00 GMT')));
    }

    public function testTransportFailureIsANetworkErrorAndRedactsTheKey(): void
    {
        $t = new class() implements Transport {
            public int $calls = 0;

            public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): \Recodive\Hansho\Http\TransportResponse
            {
                $this->calls++;
                throw new \RuntimeException('connect failed for ' . $headers['Authorization']);
            }
        };
        $c = new Hansho('http://hub.test', FakeTransport::KEY, transport: $t, sleep: fn () => null);
        try {
            $c->getChannel('ops');
            $this->fail('expected NetworkException');
        } catch (NetworkException $e) {
            $this->assertNotInstanceOf(ApiException::class, $e);
            $this->assertInstanceOf(HanshoException::class, $e);
            $this->assertStringNotContainsString(FakeTransport::KEY, $e->getMessage());
            $this->assertStringContainsString('[redacted]', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
        $this->assertSame(1, $t->calls, 'network errors are not retried');
    }

    public function testInvalidJsonOnSuccessIsANetworkError(): void
    {
        [$c] = FakeTransport::client(fn () => FakeTransport::reply(200, null, [], '<html>'));
        $this->expectException(NetworkException::class);
        $c->getChannel('ops');
    }

    public function testErrorMessagesNeverContainSecretsOrBodies(): void
    {
        [$c] = FakeTransport::client(fn () => FakeTransport::reply(400, ['error' => 'invalid_request', 'field' => 'title', 'message' => 'bad ' . FakeTransport::KEY]));
        try {
            $c->sendMessage(new Message('u', 'c', '', 'super secret body'));
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame('hansho: POST /api/v1/messages: 400 invalid_request (field title)', $e->getMessage());
            $this->assertSame('bad [redacted]', $e->detail);
            $this->assertStringNotContainsString(FakeTransport::KEY, (string) $e);
            $this->assertStringNotContainsString('super secret body', $e->getMessage());
        }
    }

    public function testClientDoesNotLeakTheKeyWhenDumped(): void
    {
        [$c] = FakeTransport::client(fn () => FakeTransport::reply(200, []));
        ob_start();
        var_dump($c);
        $dump = (string) ob_get_clean();
        $this->assertStringNotContainsString(FakeTransport::KEY, $dump);
    }

    public function testIdsArePathEscaped(): void
    {
        [$c, $t] = FakeTransport::client(fn () => FakeTransport::reply(200, []));
        $c->getChannel('a/b?c d#e%ü');
        $c->unpair('u-1 ü/42?x');
        $this->assertSame('/api/v1/channels/a%2Fb%3Fc%20d%23e%25%C3%BC', $t->requests[0]['path']);
        $this->assertSame('/api/v1/users/u-1%20%C3%BC%2F42%3Fx/pairings', $t->requests[1]['path']);
    }

    public function testEmptyAndDotIdsAreRejectedBeforeAnyRequest(): void
    {
        [$c, $t] = FakeTransport::client(fn () => FakeTransport::reply(200, []));
        foreach (['', '.', '..'] as $id) {
            try {
                $c->getChannel($id);
                $this->fail("id '$id' accepted");
            } catch (\InvalidArgumentException) {
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $c->createPairingTicket('');
        $this->assertSame([], $t->requests);
    }

    public function testBaseUrlTrailingSlashAndUserAgent(): void
    {
        [$c, $t] = FakeTransport::client(fn () => FakeTransport::reply(200, []));
        $c->getChannel('ops');
        $this->assertSame('http://hub.test/api/v1/channels/ops', $t->requests[0]['url']);
        $this->assertSame('hansho-php/' . Hansho::VERSION, $t->requests[0]['headers']['user-agent']);
    }

    public function testConstructorValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Hansho('http://hub.test', '');
    }

    public function testOversizedSoundIsRejectedLocally(): void
    {
        [$c, $t] = FakeTransport::client(fn () => FakeTransport::reply(200, []));
        try {
            $c->putSound('s', 'audio/wav', str_repeat('x', (1 << 20) + 1));
            $this->fail('expected RangeException');
        } catch (\RangeException) {
        }
        $this->assertSame([], $t->requests);
    }

    public function testBodyEncodingKeepsSlashesUnicodeAndFloats(): void
    {
        [$c, $t] = FakeTransport::client(fn () => FakeTransport::reply(202, ['message_id' => 'm']));
        $c->sendMessage(new Message('ü', 'c', 'Ünï/x', 'b', data: ['v' => 1.0, 'empty' => new \stdClass()]));
        $this->assertStringContainsString('"title":"Ünï/x"', (string) $t->requests[0]['body']);
        $this->assertStringContainsString('"v":1.0', (string) $t->requests[0]['body']);
        $this->assertStringContainsString('"empty":{}', (string) $t->requests[0]['body']);
    }

    public function testUnknownResponseFieldsAreIgnored(): void
    {
        [$c] = FakeTransport::client(fn () => FakeTransport::reply(200, ['message_id' => 'm', 'status' => 'queued', 'level' => 'active', 'attempts' => 0, 'brand_new' => ['x' => 1], 'deliveries' => [['device_id' => 'd', 'status' => 's', 'extra' => 1]]]));
        $s = $c->getMessage('m');
        $this->assertSame('queued', $s->status);
        $this->assertNull($s->ackedAt);
        $this->assertCount(1, $s->deliveries);
    }
}
