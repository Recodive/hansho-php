<?php

declare(strict_types=1);

namespace Recodive\Hansho\Tests;

use PHPUnit\Framework\TestCase;
use Recodive\Hansho\Action;
use Recodive\Hansho\Activity;
use Recodive\Hansho\ActivityAlert;
use Recodive\Hansho\ActivityUpdate;
use Recodive\Hansho\Channel;
use Recodive\Hansho\Exception\ApiException;
use Recodive\Hansho\Feed;
use Recodive\Hansho\Hansho;
use Recodive\Hansho\Level;
use Recodive\Hansho\Message;
use Recodive\Hansho\MessageStatus;
use Recodive\Hansho\PageOptions;

/**
 * End to end against the real hub. Skipped unless HANSHO_HUB_URL is set; run it through sdk/scripts/run-integration.sh,
 * which starts the hub and a stub relay and exports HANSHO_HUB_URL, HANSHO_API_KEY, HANSHO_WEBHOOK_SECRET,
 * HANSHO_WEBHOOK_ADDR and HANSHO_FAKEDEVICE_BIN.
 *
 * PHP is single threaded, so the webhook receiver is a `php -S` child process (tests/support/webhook_receiver.php)
 * that verifies each request with the SDK and records it in a file. HANSHO_WEBHOOK_BIND, when set, is the address it
 * binds instead of HANSHO_WEBHOOK_ADDR (for a container whose published port the hub reaches on another address).
 */
final class IntegrationTest extends TestCase
{
    /** @var resource|null */
    private $receiver = null;
    private string $dir = '';
    private string $events = '';
    private string $rejected = '';

    protected function setUp(): void
    {
        if ((string) getenv('HANSHO_HUB_URL') === '') {
            $this->markTestSkipped('HANSHO_HUB_URL is not set; run sdk/scripts/run-integration.sh');
        }
    }

    protected function tearDown(): void
    {
        if (is_resource($this->receiver)) {
            proc_terminate($this->receiver);
            proc_close($this->receiver);
        }
    }

    private static function env(string $name): string
    {
        $v = getenv($name);
        if ($v === false || $v === '') {
            throw new \RuntimeException("$name is not set");
        }

        return $v;
    }

    private function startReceiver(): void
    {
        $this->dir = sys_get_temp_dir() . '/hansho-php-it-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        $this->events = $this->dir . '/events.jsonl';
        $this->rejected = $this->dir . '/rejected.txt';
        touch($this->events);
        touch($this->rejected);
        $addr = getenv('HANSHO_WEBHOOK_BIND') ?: self::env('HANSHO_WEBHOOK_ADDR');
        $env = [
            'PATH' => (string) getenv('PATH'),
            'HANSHO_WEBHOOK_SECRET' => self::env('HANSHO_WEBHOOK_SECRET'),
            'HANSHO_TEST_EVENTS' => $this->events,
            'HANSHO_TEST_REJECTED' => $this->rejected,
            'PHP_CLI_SERVER_WORKERS' => '2',
        ];
        $cmd = [PHP_BINARY, '-S', $addr, __DIR__ . '/support/webhook_receiver.php'];
        $this->receiver = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->dir . '/receiver.log', 'w'], 2 => ['file', $this->dir . '/receiver.log', 'w']], $pipes, null, $env);
        $this->assertIsResource($this->receiver);
        [$host, $port] = explode(':', $addr);
        $host = $host === '0.0.0.0' ? '127.0.0.1' : $host;
        for ($i = 0; $i < 100; $i++) {
            $s = @fsockopen($host, (int) $port, $errno, $errstr, 0.2);
            if ($s !== false) {
                fclose($s);

                return;
            }
            usleep(100_000);
        }
        $this->fail('webhook receiver did not start: ' . (string) @file_get_contents($this->dir . '/receiver.log'));
    }

    /** @return list<array{type:string,id:string,name:?string,data:array<string,mixed>}> */
    private function received(): array
    {
        $out = [];
        foreach (file($this->events, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $out[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }

        return $out;
    }

    /**
     * @param callable(array<string,mixed>): bool $match
     *
     * @return array<string,mixed>
     */
    private function waitEvent(string $type, callable $match): array
    {
        $deadline = microtime(true) + 25;
        while (microtime(true) < $deadline) {
            foreach ($this->received() as $e) {
                if ($e['type'] === $type && $match($e['data'])) {
                    return $e['data'];
                }
            }
            usleep(100_000);
        }
        $this->fail("no $type webhook arrived");
    }

    private function waitStatus(Hansho $c, string $id, string ...$want): MessageStatus
    {
        $deadline = microtime(true) + 25;
        $last = '';
        while (microtime(true) < $deadline) {
            $ms = $c->getMessage($id);
            $last = $ms->status;
            if (in_array($ms->status, $want, true)) {
                return $ms;
            }
            usleep(200_000);
        }
        $this->fail("message $id: status $last, wanted one of " . implode(', ', $want));
    }

    private function fakeDevice(string ...$args): string
    {
        $cmd = escapeshellarg(self::env('HANSHO_FAKEDEVICE_BIN'));
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        exec($cmd . ' 2>&1', $lines, $code);
        $out = implode("\n", $lines);
        $this->assertSame(0, $code, "fakedevice failed: $out");

        return $out;
    }

    private static function tinyWav(): string
    {
        $data = str_repeat("\0", 800); // 0.05 s of 8 kHz 8-bit mono silence

        return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8) . 'data' . pack('V', strlen($data)) . $data;
    }

    /** @param callable(): mixed $call */
    private function assertApiError(callable $call, int $status, string $code, ?string $field = null): void
    {
        try {
            $call();
        } catch (ApiException $e) {
            $this->assertSame($status, $e->status, $e->getMessage());
            $this->assertSame($code, $e->apiCode, $e->getMessage());
            if ($field !== null) {
                $this->assertSame($field, $e->field, $e->getMessage());
            }

            return;
        }
        $this->fail("expected ApiException $status $code");
    }

    public function testRunsTheWholeFlow(): void
    {
        $hubUrl = self::env('HANSHO_HUB_URL');
        $this->startReceiver();
        $c = new Hansho($hubUrl, self::env('HANSHO_API_KEY'));
        $p = 'php';
        $user = "u-$p ü/42?x";
        [$ch, $tpl, $snd] = ["$p-ops", "$p-status", "$p-ding"];
        $statePath = $this->dir . '/device.json';

        // errors
        $this->assertApiError(fn () => (new Hansho($hubUrl, 'ck_not_a_key'))->createPairingTicket('x'), 401, 'unauthorized');
        $this->assertApiError(fn () => $c->getChannel('no-such-channel'), 404, 'unknown_channel');
        $this->assertApiError(fn () => $c->sendMessage(new Message($user, $ch, '', 'b')), 400, 'invalid_request', 'title');

        // catalog
        $sound = $c->putSound($snd, 'audio/wav', self::tinyWav());
        $this->assertSame($snd, $sound->id);
        $template = ['type' => 'vstack', 'tint' => ['light' => '#0A84FF', 'dark' => '#64D2FF'], 'children' => [['type' => 'text', 'style' => 'headline', 'text' => '{{title}}'], ['type' => 'spacer']]];
        $c->putTemplate($tpl, 1, $template);
        $this->assertApiError(fn () => $c->putTemplate($tpl, 1, $template), 409, 'version_conflict');
        $c->putTemplate($tpl, 2, $template);
        $c->putChannel($ch, new Channel("Operations $p", Level::Active, Level::Page, $snd, $tpl, 'ops', [new Action('ack', 'Acknowledge', true)]));
        $got = $c->getChannel($ch);
        $this->assertSame("Operations $p", $got->name);
        $this->assertSame('page', $got->maxLevel);
        $this->assertSame($snd, $got->sound);
        $this->assertSame($tpl, $got->templateId);
        $this->assertEquals([new Action('ack', 'Acknowledge', true)], $got->actions);
        $this->assertApiError(fn () => $c->putChannel('bad id', new Channel('n', Level::Active, Level::Page)), 400, 'invalid_request', 'id');

        // pairing
        $tk = $c->createPairingTicket($user);
        $this->assertStringStartsWith('hansho://pair?', $tk->qr);
        $this->assertStringContainsString($tk->ticket, $tk->qr);
        $this->assertGreaterThan(time(), $tk->expiresAt->getTimestamp());
        $this->fakeDevice('pair', '-hub', $hubUrl, '-ticket', $tk->ticket, '-state', $statePath, '-name', "SDK phone $p");
        $this->waitEvent('pairing.created', fn ($d) => $d['externalUserId'] === $user && $d['deviceName'] === "SDK phone $p");

        // messages at every level
        foreach ([Level::Passive, Level::Active, Level::TimeSensitive, Level::Critical] as $level) {
            $res = $c->sendMessage(new Message($user, $ch, 'Disk full', 'db-1 is at 97%', $level, data: ['disk' => 97], sound: $snd, collapseId: "disk-{$level->value}"));
            $this->assertSame($level->value, $res->level);
            $this->assertSame(1, $res->devices);
            $this->assertStringStartsWith('msg_', $res->messageId);
            $ms = $this->waitStatus($c, $res->messageId, 'delivered');
            $this->assertSame($level->value, $ms->level);
            $this->assertCount(1, $ms->deliveries);
            $this->assertSame('sent', $ms->deliveries[0]->status);
            $this->waitEvent('message.delivered', fn ($d) => $d['messageId'] === $res->messageId && $d['level'] === $level->value && $d['devices'] === 1);
        }
        $def = $c->sendMessage(new Message($user, $ch, 'Default', 'b'));
        $this->assertSame('active', $def->level);

        // page + acknowledgement from the (fake) device
        $page = $c->sendMessage(new Message($user, $ch, 'Primary DB down', 'Failover failed', Level::Page, page: new PageOptions(60, 3)));
        $this->assertSame('page', $page->level);
        $this->waitStatus($c, $page->messageId, 'paging');
        $this->fakeDevice('ack', '-state', $statePath, '-message', $page->messageId);
        $acked = $this->waitStatus($c, $page->messageId, 'acked');
        $this->assertInstanceOf(\DateTimeImmutable::class, $acked->ackedAt);
        $ev = $this->waitEvent('message.acked', fn ($d) => $d['messageId'] === $page->messageId);
        $this->assertSame($user, $ev['externalUserId']);
        $this->waitEvent('action', fn ($d) => $d['messageId'] === $page->messageId && $d['actionId'] === 'ack');

        // live activity
        $started = $c->startActivity(new Activity($user, $ch, $tpl, ['title' => 'Deploy', 'progress' => 0.1], new ActivityAlert('Deploy started')));
        $this->assertSame(1, $started->devices);
        $this->assertStringStartsWith('act_', $started->activityId);
        $this->fakeDevice('activity-token', '-state', $statePath, '-activity', $started->activityId);
        $up = $c->updateActivity($started->activityId, new ActivityUpdate(['title' => 'Deploy', 'progress' => 0.6], 10));
        $this->assertSame($started->activityId, $up->activityId);
        $this->assertGreaterThanOrEqual(1, $up->update);
        $c->endActivity($started->activityId);
        $this->assertApiError(fn () => $c->updateActivity($started->activityId, new ActivityUpdate(['title' => 'x'])), 409, 'activity_ended');

        // feeds
        $own = $c->putFeed("$p-uptime", new Feed($tpl, ['title' => 'up', 'ms' => 41], $user));
        $this->assertSame("$p-uptime", $own->feedId);
        $this->assertGreaterThanOrEqual(1, $own->devices);
        $shared = $c->putFeed("$p-shared", new Feed($tpl, ['title' => 'all']));
        $this->assertGreaterThanOrEqual(1, $shared->devices);
        $this->assertApiError(fn () => $c->putFeed("$p-bad", new Feed('missing', 1)), 400, 'invalid_request', 'template_id');

        // unpair, then nobody is left to deliver to
        $c->unpair($user);
        $this->waitEvent('pairing.revoked', fn ($d) => $d['externalUserId'] === $user && $d['by'] === 'sender');
        $none = $c->sendMessage(new Message($user, $ch, 'Anyone?', 'b'));
        $this->assertSame(0, $none->devices);
        $this->waitStatus($c, $none->messageId, 'undeliverable');
        $this->waitEvent('message.undeliverable', fn ($d) => $d['messageId'] === $none->messageId);
        $c->deleteChannel($ch);
        $this->assertApiError(fn () => $c->getChannel($ch), 404, 'unknown_channel');

        $this->assertSame('', trim((string) file_get_contents($this->rejected)), 'the SDK rejected a webhook the hub signed');
        $this->assertNotEmpty($this->received());
    }
}
