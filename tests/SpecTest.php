<?php

declare(strict_types=1);

namespace Recodive\Hansho\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Recodive\Hansho\Action;
use Recodive\Hansho\Activity;
use Recodive\Hansho\ActivityAlert;
use Recodive\Hansho\ActivityStarted;
use Recodive\Hansho\ActivityUpdate;
use Recodive\Hansho\ActivityUpdated;
use Recodive\Hansho\Audience;
use Recodive\Hansho\AudienceMode;
use Recodive\Hansho\Channel;
use Recodive\Hansho\Delivery;
use Recodive\Hansho\Exception\ApiException;
use Recodive\Hansho\Feed;
use Recodive\Hansho\FeedResult;
use Recodive\Hansho\Group;
use Recodive\Hansho\Hansho;
use Recodive\Hansho\Level;
use Recodive\Hansho\Message;
use Recodive\Hansho\MessageResult;
use Recodive\Hansho\MessageStatus;
use Recodive\Hansho\PageOptions;
use Recodive\Hansho\PairingTicket;
use Recodive\Hansho\Recipient;
use Recodive\Hansho\SoundResult;

/** Every operation and error of the shared sdk/spec/api.json. */
final class SpecTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function spec(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../../spec/api.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,array{Closure(Hansho): mixed, mixed}> */
    private static function calls(): array
    {
        $ack = [new Action('ack', 'Acknowledge', true), new Action('reply', 'Reply', kind: 'text')];
        $only = new Audience(AudienceMode::Only, ['alice'], ['oncall']);

        return [
            'createPairingTicket' => [fn (Hansho $c) => $c->createPairingTicket('user-42'),
                new PairingTicket('tk_abc', 'hansho://pair?hub=https%3A%2F%2Fhub.example.com&t=tk_abc&fp=ff00', new \DateTimeImmutable('2026-11-08T12:10:00Z'))],
            'unpair' => [fn (Hansho $c) => $c->unpair('user-42'), null],
            'putChannel' => [fn (Hansho $c) => $c->putChannel('ops', new Channel('Operations', Level::Active, Level::Critical, 'ding', 'status', 'ops-thread', $ack)), null],
            'getChannel' => [fn (Hansho $c) => $c->getChannel('ops'), new Channel('Operations', 'active', 'critical', '', '', 'ops', [])],
            'deleteChannel' => [fn (Hansho $c) => $c->deleteChannel('ops'), null],
            'putTemplate' => [fn (Hansho $c) => $c->putTemplate('status', 2, ['type' => 'vstack', 'children' => [['type' => 'text', 'style' => 'headline', 'text' => '{{title}}']]]), null],
            'putSound' => [fn (Hansho $c) => $c->putSound('ding', 'audio/wav', (string) base64_decode('UklGRgAAAABXQVZF', true)), new SoundResult('ding', 'ding.wav', '00', 12)],
            'sendMessage' => [fn (Hansho $c) => $c->sendMessage(new Message(
                externalUserId: 'user-42',
                channel: 'ops',
                title: 'Disk full',
                body: 'db-1 is at 97%',
                level: Level::TimeSensitive,
                subtitle: 'db-1',
                imageUrl: 'https://example.com/i.png',
                templateId: 'status',
                data: ['disk' => 97, 'nested' => ['snake_case_key' => [1, 2]]],
                sound: 'ding',
                actions: [new Action('ack', 'Ack', true)],
                collapseId: 'disk-db-1',
            )), new MessageResult('msg_1', 'time-sensitive', 2, 'queued', [new Delivery('dev_1', 'queued'), new Delivery('dev_2', 'queued')])],
            'sendPage' => [fn (Hansho $c) => $c->sendMessage(new Message(
                externalUserId: 'user-42',
                channel: 'ops',
                title: 'Primary DB down',
                body: 'Failover did not complete',
                level: Level::Page,
                page: new PageOptions(60, 3),
            )), new MessageResult('msg_2', 'page', 1, 'queued', [new Delivery('dev_1', 'queued')])],
            'getMessage' => [fn (Hansho $c) => $c->getMessage('msg_2'), new MessageStatus('msg_2', 'acked', 'page', 2,
                [new Delivery('dev_1', 'sent'), new Delivery('dev_2', 'failed', 'unregistered')], new \DateTimeImmutable('2026-11-08T12:05:00Z'))],
            'startActivity' => [fn (Hansho $c) => $c->startActivity(new Activity('user-42', 'ops', 'deploy', ['progress' => 0.1], new ActivityAlert('Deploy started', 'v1.2.3'))), new ActivityStarted('act_1', 1)],
            'updateActivity' => [fn (Hansho $c) => $c->updateActivity('act_1', new ActivityUpdate(['progress' => 0.6], 10, new ActivityAlert('Halfway', '60%'))), new ActivityUpdated('act_1', 3)],
            'endActivity' => [fn (Hansho $c) => $c->endActivity('act_1'), null],
            'putFeed' => [fn (Hansho $c) => $c->putFeed('uptime', new Feed('status', ['up' => true, 'ms' => 41], 'user-42', 'Uptime')), new FeedResult('uptime', 2)],
            'putGroup' => [fn (Hansho $c) => $c->putGroup('oncall', ['alice', 'bob']), new Group('oncall', ['alice', 'bob'])],
            'getGroup' => [fn (Hansho $c) => $c->getGroup('oncall'), new Group('oncall', ['alice', 'bob'])],
            'deleteGroup' => [fn (Hansho $c) => $c->deleteGroup('oncall'), null],
            'kick' => [fn (Hansho $c) => $c->kick('user-42'), null],
            'putChannelWithAudience' => [fn (Hansho $c) => $c->putChannel('ops', new Channel('Operations', Level::Active, Level::Critical, audience: $only)), null],
            'putFeedWithChannel' => [fn (Hansho $c) => $c->putFeed('uptime', new Feed('status', ['up' => true], channel: 'ops', audience: $only)), new FeedResult('uptime', 1)],
            'sendToAudience' => [fn (Hansho $c) => $c->sendMessage(new Message(null, 'ops', 'Deploy done', 'v1.2.3 is live', toAudience: true)),
                new MessageResult('', 'active', 0, '', [], [
                    new Recipient('alice', 'queued', 'msg_7', 1, [new Delivery('dev_1', 'queued')]),
                    new Recipient('carol', Recipient::NO_ACCESS),
                ])],
        ];
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function operations(): iterable
    {
        foreach (self::spec()['operations'] as $op) {
            yield $op['name'] => [$op];
        }
    }

    /** @param array<string,mixed> $op */
    #[DataProvider('operations')]
    public function testOperation(array $op): void
    {
        $calls = self::calls();
        $this->assertArrayHasKey($op['name'], $calls, "no SDK call for spec operation {$op['name']}");
        [$call, $want] = $calls[$op['name']];
        [$client, $t] = FakeTransport::client(fn () => FakeTransport::reply($op['response']['status'], $op['response']['body']));

        $got = $call($client);

        $this->assertEquals($want, $got);
        $this->assertCount(1, $t->requests);
        $r = $t->requests[0];
        $this->assertSame($op['method'], $r['method']);
        $this->assertSame($op['path'], $r['path']);
        $this->assertSame('Bearer ' . FakeTransport::KEY, $r['headers']['authorization']);
        $this->assertSame('application/json', $r['headers']['accept']);
        if ($op['request'] === null) {
            $this->assertNull($r['body']);
            $this->assertArrayNotHasKey('content-type', $r['headers']);
        } elseif (isset($op['request']['raw_base64'])) {
            $this->assertSame($op['request']['content_type'], $r['headers']['content-type']);
            $this->assertSame(base64_decode($op['request']['raw_base64'], true), $r['body']);
        } else {
            $this->assertSame($op['request']['content_type'], $r['headers']['content-type']);
            $this->assertEquals($op['request']['body'], json_decode((string) $r['body'], true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function testCoversEverySdkCall(): void
    {
        $names = array_column(self::spec()['operations'], 'name');
        $this->assertEqualsCanonicalizing($names, array_keys(self::calls()), 'SDK calls and spec operations differ');
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function errors(): iterable
    {
        foreach (self::spec()['errors'] as $i => $e) {
            yield "error $i ({$e['status']})" => [$e];
        }
    }

    /** @param array<string,mixed> $e */
    #[DataProvider('errors')]
    public function testError(array $e): void
    {
        [$client, $t] = FakeTransport::client(fn () => FakeTransport::reply($e['status'], $e['body'], $e['headers'], $e['raw'] ?? null));
        try {
            $client->sendMessage(new Message('u', 'c', 't', 'b'));
            $this->fail('expected ApiException');
        } catch (ApiException $ex) {
            $this->assertSame($e['status'], $ex->status);
            $this->assertSame($e['code'], $ex->apiCode);
            $this->assertSame($e['field'], $ex->field ?? '');
            $this->assertSame($e['retry_after_ms'], $ex->retryAfterMs ?? 0);
        }
        $this->assertCount(1, $t->requests, 'POST /messages must not be retried');
        $this->assertSame([], $t->sleeps);
    }
}
