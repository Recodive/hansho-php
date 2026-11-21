<?php

declare(strict_types=1);

namespace Recodive\Hansho\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Recodive\Hansho\Exception\WebhookException;
use Recodive\Hansho\Webhook;

final class WebhookTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../../spec/webhook-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function vectorProvider(): iterable
    {
        foreach (self::vectors()['vectors'] as $v) {
            yield $v['name'] => [$v];
        }
    }

    private static function camel(string $k): string
    {
        return lcfirst(str_replace('_', '', ucwords($k, '_')));
    }

    private static function sign(string $secret, int $at, string $body): string
    {
        return "t=$at,v1=" . hash_hmac('sha256', "$at.$body", $secret);
    }

    public function testVectorFileIsNotEmpty(): void
    {
        $this->assertGreaterThanOrEqual(30, count(self::vectors()['vectors']));
        $this->assertSame(300, self::vectors()['tolerance_seconds']);
    }

    /** @param array<string,mixed> $v */
    #[DataProvider('vectorProvider')]
    public function testVector(array $v): void
    {
        $exp = $v['expect'];
        if (!$exp['ok']) {
            try {
                Webhook::verify($v['secret'], $v['header'], $v['body'], $v['now']);
                $this->fail("accepted a vector that must fail with {$exp['error']}");
            } catch (WebhookException $e) {
                $this->assertSame($exp['error'], $e->reason);
            }

            return;
        }
        $ev = Webhook::verify($v['secret'], $v['header'], $v['body'], $v['now']);
        $this->assertSame($exp['type'], $ev->type);
        $this->assertSame($exp['id'], $ev->id);
        $this->assertSame($exp['created_at'], $ev->createdAt->getTimestamp());
        $this->assertSame($v['body'], $ev->raw);
        if ($exp['type'] === 'unknown') {
            $this->assertSame($exp['name'], $ev->name);
            $this->assertSame($exp['data'], $ev->data);

            return;
        }
        $want = [];
        foreach ($exp['data'] as $k => $val) {
            $want[self::camel($k)] = $val;
        }
        $this->assertEquals($want, $ev->data);
    }

    private static function valid(): array
    {
        foreach (self::vectors()['vectors'] as $v) {
            if ($v['name'] === 'valid message.acked') {
                return $v;
            }
        }
        throw new \LogicException('vector missing');
    }

    public function testRejectsNonStringBodies(): void
    {
        $v = self::valid();
        foreach ([json_decode($v['body'], true), null, 42, new \stdClass()] as $body) {
            try {
                Webhook::verify($v['secret'], $v['header'], $body, $v['now']);
                $this->fail('accepted a non-string body');
            } catch (WebhookException $e) {
                $this->assertSame('invalid_body', $e->reason);
            }
        }
    }

    public function testRejectsReserialisedBody(): void
    {
        $v = self::valid();
        $parsed = json_decode($v['body'], true);
        foreach ([json_encode($parsed, JSON_PRETTY_PRINT), $v['body'] . "\n"] as $body) {
            try {
                Webhook::verify($v['secret'], $v['header'], $body, $v['now']);
                $this->fail('accepted a re-serialised body');
            } catch (WebhookException $e) {
                $this->assertSame('bad_signature', $e->reason);
            }
        }
    }

    public function testEmptySecretAndNullHeader(): void
    {
        $v = self::valid();
        try {
            Webhook::verify('', $v['header'], $v['body'], $v['now']);
            $this->fail('accepted an empty secret');
        } catch (WebhookException $e) {
            $this->assertSame('invalid_secret', $e->reason);
        }
        try {
            Webhook::verify($v['secret'], null, $v['body'], $v['now']);
            $this->fail('accepted a missing header');
        } catch (WebhookException $e) {
            $this->assertSame('malformed_header', $e->reason);
        }
    }

    public function testToleranceAndDefaultNow(): void
    {
        $body = '{"id":"1","event":"message.acked","created_at":1,"data":{}}';
        $secret = 'whsec_x';
        $now = time();
        $this->assertSame('message.acked', Webhook::verify($secret, self::sign($secret, $now, $body), $body)->type);
        $this->assertSame('message.acked', Webhook::verify($secret, self::sign($secret, $now - 10, $body), $body, null, 10 + 5)->type);
        try {
            Webhook::verify($secret, self::sign($secret, $now - 600, $body), $body);
            $this->fail('accepted a stale webhook');
        } catch (WebhookException $e) {
            $this->assertSame('stale', $e->reason);
        }
        $this->assertSame('message.acked', Webhook::verify($secret, self::sign($secret, $now - 600, $body), $body, null, 601)->type);
        // <= 0 means the default 300 s
        $this->expectException(WebhookException::class);
        Webhook::verify($secret, self::sign($secret, $now - 600, $body), $body, null, 0);
    }

    public function testMalformedBodies(): void
    {
        $secret = 's';
        foreach (['not json', '[1,2]', '{"id":"1"}', '{"event":""}', "\xff\xfe", '"str"', '[]'] as $body) {
            try {
                Webhook::verify($secret, self::sign($secret, 1700000000, $body), $body, 1700000000);
                $this->fail("accepted body $body");
            } catch (WebhookException $e) {
                $this->assertSame('malformed_body', $e->reason, $body);
            }
        }
    }

    public function testNonAsciiBodyBytesAreVerifiedAsGiven(): void
    {
        $body = '{"id":"9","event":"pairing.created","created_at":1700000000,"data":{"device_name":"Bas’s iPhone <Ünï>","external_user_id":"ü","pairing_id":"p"}}';
        $ev = Webhook::verify('k', self::sign('k', 1700000000, $body), $body, 1700000000);
        $this->assertSame('Bas’s iPhone <Ünï>', $ev->data['deviceName']);
    }

    public function testActionTextIsOnlyPresentForTextActions(): void
    {
        $body = '{"id":"9","event":"action","created_at":1700000000,"data":{"action_id":"ack","device_id":"d","external_user_id":"u","message_id":"m"}}';
        $ev = Webhook::verify('k', self::sign('k', 1700000000, $body), $body, 1700000000);
        $this->assertArrayNotHasKey('text', $ev->data);
        $this->assertSame('ack', $ev->data['actionId']);
    }
}
