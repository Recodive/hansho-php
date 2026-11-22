<?php

declare(strict_types=1);

namespace Recodive\Hansho\Tests;

use PHPUnit\Framework\TestCase;
use Recodive\Hansho\Activity;
use Recodive\Hansho\Audience;
use Recodive\Hansho\AudienceMode;
use Recodive\Hansho\Channel;
use Recodive\Hansho\Exception\ApiException;
use Recodive\Hansho\Feed;
use Recodive\Hansho\FeedSettings;
use Recodive\Hansho\Group;
use Recodive\Hansho\Message;
use Recodive\Hansho\Recipient;

/** Groups, audiences, kick and per-recipient send results. */
final class AudienceTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function body(FakeTransport $t, int $i): array
    {
        return json_decode((string) $t->requests[$i]['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function testGroupsArePutReadAndDeleted(): void
    {
        [$c, $t] = FakeTransport::client(fn ($r) => FakeTransport::reply(200, $r['method'] === 'DELETE' ? null : ['id' => 'on call', 'members' => ['alice', 'bob']]));

        $this->assertEquals(new Group('on call', ['alice', 'bob']), $c->putGroup('on call', ['alice', 'bob']));
        $this->assertSame('PUT', $t->requests[0]['method']);
        $this->assertSame('/api/v1/groups/on%20call', $t->requests[0]['path']);
        $this->assertSame(['members' => ['alice', 'bob']], self::body($t, 0));

        $c->putGroup('empty', []);
        $this->assertSame('{"members":[]}', $t->requests[1]['body'], 'the hub requires the list, even an empty one');

        $this->assertSame(['alice', 'bob'], $c->getGroup('on call')->members);
        $this->assertSame('GET', $t->requests[2]['method']);
        $c->deleteGroup('on call');
        $this->assertSame('DELETE', $t->requests[3]['method']);
        $this->assertSame('/api/v1/groups/on%20call', $t->requests[3]['path']);
    }

    public function testEmptyGroupIdIsRejectedBeforeAnyRequest(): void
    {
        [$c, $t] = FakeTransport::client(fn () => FakeTransport::reply(200, []));
        try {
            $c->getGroup('');
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame([], $t->requests);
    }

    public function testMissingGroupIsUnknownGroup(): void
    {
        [$c] = FakeTransport::client(fn () => FakeTransport::reply(404, ['error' => 'unknown_group']));
        try {
            $c->getGroup('nope');
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->status);
            $this->assertSame(ApiException::UNKNOWN_GROUP, $e->apiCode);
        }
    }

    public function testChannelSendsItsAudienceOnlyWhenSet(): void
    {
        [$c, $t] = FakeTransport::client(fn () => FakeTransport::reply(200, []));
        $c->putChannel('pager', new Channel('Pager', 'page', 'page'));
        $this->assertArrayNotHasKey('audience', self::body($t, 0), 'absent keeps the stored audience on the hub');

        $c->putChannel('pager', new Channel('Pager', 'page', 'page', audience: new Audience(AudienceMode::Only, ['carol'], ['oncall'])));
        $this->assertSame(['mode' => 'only', 'users' => ['carol'], 'groups' => ['oncall']], self::body($t, 1)['audience']);

        $c->putChannel('pager', new Channel('Pager', 'page', 'page', audience: new Audience('everyone')));
        $this->assertSame('{"mode":"everyone"}', json_encode(self::body($t, 2)['audience']), 'an explicit everyone opens the channel');
    }

    public function testGetChannelReadsTheAudience(): void
    {
        [$c] = FakeTransport::client(fn () => FakeTransport::reply(200, [
            'name' => 'Pager', 'default_level' => 'page', 'max_level' => 'page',
            'audience' => ['mode' => 'except', 'users' => ['bob'], 'groups' => null],
        ]));
        $this->assertEquals(new Audience('except', ['bob'], []), $c->getChannel('pager')->audience);
    }

    public function testFeedsCarryChannelAndAudienceAndAreReadBack(): void
    {
        [$c, $t] = FakeTransport::client(fn ($r) => $r['method'] === 'GET'
            ? FakeTransport::reply(200, ['feed_id' => 'queue', 'template_id' => 'status', 'channel' => 'pager', 'updated_at' => 1794139800])
            : FakeTransport::reply(200, ['feed_id' => 'queue', 'devices' => 1]));

        $c->putFeed('queue', new Feed('status', 1, channel: 'pager', audience: new Audience(AudienceMode::Only, groups: ['oncall'])));
        $this->assertSame('pager', self::body($t, 0)['channel']);
        $this->assertSame(['mode' => 'only', 'groups' => ['oncall']], self::body($t, 0)['audience']);

        $c->putFeed('queue', new Feed('status', 1));
        $this->assertArrayNotHasKey('channel', self::body($t, 1));
        $this->assertArrayNotHasKey('audience', self::body($t, 1));

        $this->assertEquals(new FeedSettings('queue', 'status', channel: 'pager', updatedAt: 1794139800), $c->getFeed('queue'));
        $this->assertSame('/api/v1/feeds/queue', $t->requests[2]['path']);
        $c->getFeed('queue', 'u 1');
        $this->assertSame('/api/v1/feeds/queue?external_user_id=u%201', $t->requests[3]['path']);
    }

    public function testKickDeletesTheUsersPairings(): void
    {
        [$c, $t] = FakeTransport::client(fn () => FakeTransport::reply(200, []));
        $c->kick('user 42');
        $this->assertSame('DELETE', $t->requests[0]['method']);
        $this->assertSame('/api/v1/users/user%2042/pairings', $t->requests[0]['path']);
    }

    public function testSendTargetsAreSerialisedOneAtATime(): void
    {
        [$c, $t] = FakeTransport::client(fn () => FakeTransport::reply(202, ['level' => 'active']));

        $c->sendMessage(new Message(null, 'pager', 't', 'b', externalUserIds: ['alice', 'carol']));
        $b = self::body($t, 0);
        $this->assertSame(['alice', 'carol'], $b['external_user_ids']);
        $this->assertArrayNotHasKey('external_user_id', $b);
        $this->assertArrayNotHasKey('to_audience', $b);

        $c->sendMessage(new Message(null, 'pager', 't', 'b', toAudience: true));
        $b = self::body($t, 1);
        $this->assertTrue($b['to_audience']);
        $this->assertArrayNotHasKey('external_user_id', $b);
        $this->assertArrayNotHasKey('external_user_ids', $b);

        $c->sendMessage(new Message('alice', 'pager', 't', 'b'));
        $b = self::body($t, 2);
        $this->assertSame('alice', $b['external_user_id']);
        $this->assertArrayNotHasKey('external_user_ids', $b);
        $this->assertArrayNotHasKey('to_audience', $b);
    }

    public function testPerRecipientResultsAndTheOldTopLevelFields(): void
    {
        [$c] = FakeTransport::client(fn ($r) => str_contains((string) $r['body'], 'external_user_ids')
            ? FakeTransport::reply(202, ['level' => 'page', 'recipients' => [
                ['external_user_id' => 'alice', 'message_id' => 'msg_1', 'status' => 'queued', 'devices' => 1, 'deliveries' => [['device_id' => 'd1', 'status' => 'queued']]],
                ['external_user_id' => 'carol', 'status' => 'no_access'],
            ]])
            : FakeTransport::reply(202, ['message_id' => '', 'level' => 'page', 'devices' => 0, 'status' => 'no_access', 'deliveries' => [],
                'recipients' => [['external_user_id' => 'carol', 'status' => 'no_access']]]));

        $res = $c->sendMessage(new Message(null, 'pager', 't', 'b', externalUserIds: ['alice', 'carol']));
        $this->assertSame('', $res->messageId);
        $this->assertSame('page', $res->level);
        $this->assertCount(2, $res->recipients);
        $this->assertSame('msg_1', $res->recipients[0]->messageId);
        $this->assertSame(1, $res->recipients[0]->devices);
        $this->assertCount(1, $res->recipients[0]->deliveries);
        $this->assertNull($res->recipients[1]->messageId);
        $this->assertSame(Recipient::NO_ACCESS, $res->recipients[1]->status);

        $one = $c->sendMessage(new Message('carol', 'pager', 't', 'b'));
        $this->assertSame(['', 'no_access', 0], [$one->messageId, $one->status, $one->devices]);
        $this->assertSame('carol', $one->recipients[0]->externalUserId);
    }

    public function testASendWithoutRecipientsStillParses(): void
    {
        [$c] = FakeTransport::client(fn () => FakeTransport::reply(202, ['message_id' => 'msg_1', 'level' => 'active', 'devices' => 1, 'status' => 'queued', 'deliveries' => []]));
        $res = $c->sendMessage(new Message('u', 'c', 't', 'b'));
        $this->assertSame('msg_1', $res->messageId);
        $this->assertSame([], $res->recipients);
    }

    public function testNoAccessAndAnUnknownGroupInAnAudienceAreApiErrors(): void
    {
        [$c] = FakeTransport::client(fn ($r) => str_contains($r['path'], 'activities')
            ? FakeTransport::reply(403, ['error' => 'no_access'])
            : FakeTransport::reply(400, ['error' => 'invalid_request', 'field' => 'audience.groups', 'message' => 'unknown group']));

        try {
            $c->startActivity(new Activity('carol', 'pager', 't', []));
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame([403, ApiException::NO_ACCESS], [$e->status, $e->apiCode]);
        }
        try {
            $c->putChannel('pager', new Channel('n', 'active', 'page', audience: new Audience(AudienceMode::Only, groups: ['ghost'])));
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame([400, 'invalid_request'], [$e->status, $e->apiCode]);
            $this->assertNotNull($e->field);
        }
    }
}
