<?php

declare(strict_types=1);

namespace Recodive\Hansho;

use Recodive\Hansho\Exception\ApiException;
use Recodive\Hansho\Exception\NetworkException;
use Recodive\Hansho\Http\CurlTransport;
use Recodive\Hansho\Http\Transport;
use Recodive\Hansho\Http\TransportResponse;

/** Client for a Hansho hub's sender API. */
final class Hansho
{
    public const NAME = 'hansho';
    /** SDK version, sent in the default User-Agent. */
    public const VERSION = '0.1.0';

    private const MAX_SOUND_BYTES = 1 << 20;

    private readonly string $baseUrl;
    private readonly Transport $transport;
    /** @var callable(int): void */
    private $sleep;
    private readonly string $userAgent;

    /**
     * @param string                $baseUrl    the hub's public URL
     * @param string                $apiKey     a sender API key from `hub apikey create`
     * @param int                   $maxRetries retries for GET/PUT/DELETE on 429 and 503; 0 disables
     * @param int                   $timeoutMs  per-attempt timeout in milliseconds; 0 disables
     * @param Transport|null        $transport  custom HTTP transport (proxies, tests); default is ext-curl
     * @param (callable(int): void)|null $sleep replaces the retry wait (milliseconds); meant for tests
     */
    public function __construct(
        string $baseUrl,
        private readonly string $apiKey,
        private readonly int $maxRetries = Retry::DEFAULT_MAX_RETRIES,
        private readonly int $timeoutMs = 30_000,
        ?string $userAgent = null,
        ?Transport $transport = null,
        ?callable $sleep = null,
    ) {
        if ($baseUrl === '') {
            throw new \InvalidArgumentException('hansho: baseUrl is required');
        }
        if ($apiKey === '') {
            throw new \InvalidArgumentException('hansho: apiKey is required');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport ?? new CurlTransport();
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
        $this->userAgent = $userAgent ?? self::NAME . '-php/' . self::VERSION;
    }

    /** Keeps the API key out of var_dump() and logs. */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl];
    }

    // --- pairing -------------------------------------------------------------

    /**
     * Creates a single-use pairing ticket (valid 10 minutes). Render `qr` as a QR code for the user to scan in the
     * Hansho app.
     */
    public function createPairingTicket(string $externalUserId): PairingTicket
    {
        self::seg($externalUserId);
        $w = $this->json('POST', '/api/v1/pairing-tickets', ['external_user_id' => $externalUserId]);

        return new PairingTicket(self::str($w, 'ticket'), self::str($w, 'qr'), new \DateTimeImmutable(self::str($w, 'expires_at')));
    }

    /** Revokes every pairing of the user. */
    public function unpair(string $externalUserId): void
    {
        $this->json('DELETE', '/api/v1/users/' . self::seg($externalUserId) . '/pairings');
    }

    /**
     * Ends the user's whole pairing, on every device. It is unpair() under its sender-side name: the app tells the person
     * that the sender ended it (a 401 pairing_revoked with reason "kicked"), where an unpair they did themselves carries
     * no reason. There is no block list; a new ticket pairs them again.
     */
    public function kick(string $externalUserId): void
    {
        $this->unpair($externalUserId);
    }

    // --- catalog -------------------------------------------------------------

    public function putChannel(string $id, Channel $channel): void
    {
        $this->json('PUT', '/api/v1/channels/' . self::seg($id), self::defined([
            'name' => $channel->name,
            'default_level' => self::level($channel->defaultLevel),
            'max_level' => self::level($channel->maxLevel),
            'sound' => $channel->sound,
            'template_id' => $channel->templateId,
            'thread' => $channel->thread,
            'actions' => self::actionsToWire($channel->actions),
            'audience' => self::audienceToWire($channel->audience),
        ]));
    }

    public function getChannel(string $id): Channel
    {
        $w = $this->json('GET', '/api/v1/channels/' . self::seg($id));

        return new Channel(
            self::str($w, 'name'),
            self::str($w, 'default_level'),
            self::str($w, 'max_level'),
            isset($w['sound']) && is_string($w['sound']) ? $w['sound'] : null,
            isset($w['template_id']) && is_string($w['template_id']) ? $w['template_id'] : null,
            isset($w['thread']) && is_string($w['thread']) ? $w['thread'] : null,
            isset($w['actions']) && is_array($w['actions']) ? array_values(array_map(self::actionFromWire(...), $w['actions'])) : null,
            self::audienceFromWire($w['audience'] ?? null),
        );
    }

    public function deleteChannel(string $id): void
    {
        $this->json('DELETE', '/api/v1/channels/' . self::seg($id));
    }

    /**
     * Creates a group or replaces its whole member list (external user ids, who may not have paired yet). Audiences that
     * name the group follow at once. Ids match [A-Za-z0-9][A-Za-z0-9_-]{0,63}.
     *
     * @param list<string> $members
     */
    public function putGroup(string $id, array $members): Group
    {
        $w = $this->json('PUT', '/api/v1/groups/' . self::seg($id), ['members' => array_values($members)]);

        return self::groupFromWire($w, $id);
    }

    /** A missing group is an ApiException with apiCode ApiException::UNKNOWN_GROUP. */
    public function getGroup(string $id): Group
    {
        return self::groupFromWire($this->json('GET', '/api/v1/groups/' . self::seg($id)), $id);
    }

    /** An audience that still names the group matches nobody through it. */
    public function deleteGroup(string $id): void
    {
        $this->json('DELETE', '/api/v1/groups/' . self::seg($id));
    }

    /**
     * Stores a template version; the version must increase. `$body` is encoded as given: pass a stdClass for an
     * empty JSON object.
     */
    public function putTemplate(string $id, int $version, mixed $body): void
    {
        $this->json('PUT', '/api/v1/templates/' . self::seg($id), ['version' => $version, 'body' => $body]);
    }

    /** Uploads a sound (audio/x-caf, audio/wav or audio/aiff, at most 1 MB). */
    public function putSound(string $id, string $contentType, string $bytes): SoundResult
    {
        $path = '/api/v1/sounds/' . self::seg($id);
        if (strlen($bytes) > self::MAX_SOUND_BYTES) {
            throw new \RangeException('hansho: sound exceeds 1 MB');
        }
        $w = $this->send('PUT', $path, $bytes, $contentType, true);

        return new SoundResult(self::str($w, 'id'), self::str($w, 'filename'), self::str($w, 'sha256'), self::int($w, 'size'));
    }

    // --- messages ------------------------------------------------------------

    /**
     * Sends a notification, or a page when the level is Level::Page. It is never retried automatically (a repeat
     * would notify twice) unless the message has an idempotencyKey.
     */
    public function sendMessage(Message $m): MessageResult
    {
        $body = self::defined([
            'external_user_id' => $m->externalUserId,
            'external_user_ids' => $m->externalUserIds === null ? null : array_values($m->externalUserIds),
            'to_audience' => $m->toAudience ? true : null,
            'channel' => $m->channel,
            'level' => $m->level === null ? null : self::level($m->level),
            'title' => $m->title,
            'subtitle' => $m->subtitle,
            'body' => $m->body,
            'image_url' => $m->imageUrl,
            'template_id' => $m->templateId,
            'data' => $m->data,
            'sound' => $m->sound,
            'actions' => self::actionsToWire($m->actions),
            'collapse_id' => $m->collapseId,
            'page' => $m->page === null ? null : self::defined([
                'interval_s' => $m->page->intervalSeconds,
                'max_retries' => $m->page->maxRetries,
            ]),
        ]);
        $retry = $m->idempotencyKey !== null && $m->idempotencyKey !== '';
        $w = $this->send(
            'POST',
            '/api/v1/messages',
            self::encode($body),
            'application/json',
            $retry,
            $retry ? ['Idempotency-Key' => (string) $m->idempotencyKey] : [],
        );

        return new MessageResult(
            self::str($w, 'message_id'),
            self::str($w, 'level'),
            self::int($w, 'devices'),
            self::str($w, 'status'),
            self::deliveries($w['deliveries'] ?? null),
            self::recipients($w['recipients'] ?? null),
        );
    }

    public function getMessage(string $id): MessageStatus
    {
        $w = $this->json('GET', '/api/v1/messages/' . self::seg($id));

        return new MessageStatus(
            self::str($w, 'message_id'),
            self::str($w, 'status'),
            self::str($w, 'level'),
            self::int($w, 'attempts'),
            self::deliveries($w['deliveries'] ?? null),
            isset($w['acked_at']) && is_string($w['acked_at']) ? new \DateTimeImmutable($w['acked_at']) : null,
        );
    }

    // --- live activities and feeds -------------------------------------------

    public function startActivity(Activity $a): ActivityStarted
    {
        $w = $this->json('POST', '/api/v1/activities', self::defined([
            'external_user_id' => $a->externalUserId,
            'channel' => $a->channel,
            'template_id' => $a->templateId,
            'data' => $a->data,
            'alert' => self::alertToWire($a->alert),
        ]));

        return new ActivityStarted(self::str($w, 'activity_id'), self::int($w, 'devices'));
    }

    /** Not retried automatically. */
    public function updateActivity(string $id, ActivityUpdate $u): ActivityUpdated
    {
        $w = $this->json('PATCH', '/api/v1/activities/' . self::seg($id), self::defined([
            'data' => $u->data,
            'priority' => $u->priority,
            'alert' => self::alertToWire($u->alert),
        ]));

        return new ActivityUpdated(self::str($w, 'activity_id'), self::int($w, 'update'));
    }

    public function endActivity(string $id): void
    {
        $this->json('DELETE', '/api/v1/activities/' . self::seg($id));
    }

    /**
     * Reads a feed's settings without its data. Omit $externalUserId for the feed shared by all users; a missing feed is
     * an ApiException with apiCode 'unknown_feed'.
     */
    public function getFeed(string $id, ?string $externalUserId = null): FeedSettings
    {
        $path = '/api/v1/feeds/' . self::seg($id);
        if ($externalUserId !== null && $externalUserId !== '') {
            $path .= '?external_user_id=' . rawurlencode($externalUserId);
        }
        $w = $this->json('GET', $path);
        $str = static fn (string $k): ?string => isset($w[$k]) && is_string($w[$k]) && $w[$k] !== '' ? $w[$k] : null;

        return new FeedSettings(
            self::str($w, 'feed_id'),
            self::str($w, 'template_id'),
            $str('external_user_id'),
            $str('title'),
            $str('channel'),
            self::audienceFromWire($w['audience'] ?? null),
            self::int($w, 'updated_at'),
        );
    }

    public function putFeed(string $id, Feed $feed): FeedResult
    {
        $w = $this->json('PUT', '/api/v1/feeds/' . self::seg($id), self::defined([
            'external_user_id' => $feed->externalUserId,
            'template_id' => $feed->templateId,
            'title' => $feed->title,
            'channel' => $feed->channel,
            'audience' => self::audienceToWire($feed->audience),
            'data' => $feed->data,
        ]));

        return new FeedResult(self::str($w, 'feed_id'), self::int($w, 'devices'));
    }

    // --- plumbing ------------------------------------------------------------

    /**
     * @param array<string,mixed>|null $body
     *
     * @return array<string,mixed>
     */
    private function json(string $method, string $path, ?array $body = null): array
    {
        return $this->send(
            $method,
            $path,
            $body === null ? null : self::encode($body),
            $body === null ? null : 'application/json',
            Retry::isIdempotent($method),
        );
    }

    /**
     * @param array<string,string> $headers
     *
     * @return array<string,mixed>
     */
    private function send(string $method, string $path, ?string $body, ?string $contentType, bool $retry, array $headers = []): array
    {
        for ($attempt = 0; ; $attempt++) {
            $res = $this->roundTrip($method, $path, $body, $contentType, $headers);
            if ($res->status >= 200 && $res->status < 300) {
                if (trim($res->body) === '') {
                    return [];
                }
                try {
                    $parsed = json_decode($res->body, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new NetworkException($method, $path, 'invalid JSON in response', $e);
                }

                return is_array($parsed) ? $parsed : [];
            }
            $err = $this->apiError($method, $path, $res);
            if (!$retry || !Retry::isRetryableStatus($res->status) || $attempt >= $this->maxRetries) {
                throw $err;
            }
            $wait = $err->retryAfterMs ?? Retry::backoffMs($attempt);
            if ($wait > Retry::MAX_WAIT_MS) {
                throw $err;
            }
            try {
                ($this->sleep)($wait);
            } catch (\Throwable $e) {
                throw new NetworkException($method, $path, $this->redact($e->getMessage()), $e);
            }
        }
    }

    /** @param array<string,string> $extra */
    private function roundTrip(string $method, string $path, ?string $body, ?string $contentType, array $extra): TransportResponse
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ] + $extra;
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }
        try {
            return $this->transport->send($method, $this->baseUrl . $path, $headers, $body, $this->timeoutMs / 1000);
        } catch (\Throwable $e) {
            throw new NetworkException($method, $path, $this->redact($e->getMessage()), $e);
        }
    }

    private function redact(string $text): string
    {
        return str_replace($this->apiKey, '[redacted]', $text);
    }

    private function apiError(string $method, string $path, TransportResponse $res): ApiException
    {
        $code = '';
        $field = null;
        $detail = null;
        $p = json_decode($res->body, true);
        if (is_array($p)) {
            if (isset($p['error']) && is_string($p['error'])) {
                $code = $p['error'];
            }
            if (isset($p['field']) && is_string($p['field']) && $p['field'] !== '') {
                $field = $p['field'];
            }
            if (isset($p['message']) && is_string($p['message'])) {
                $detail = $this->redact($p['message']);
            }
        }
        $ra = Retry::parseRetryAfter($res->headers['retry-after'] ?? null);

        return new ApiException($method, $path, $res->status, $code, $field, $ra > 0 ? $ra : null, $detail);
    }

    /** Escapes one path segment; rejects ids that would not stay in their collection. */
    private static function seg(string $id): string
    {
        if ($id === '') {
            throw new \InvalidArgumentException('hansho: id must not be empty');
        }
        if ($id === '.' || $id === '..') {
            throw new \InvalidArgumentException("hansho: id must not be \"$id\"");
        }

        return rawurlencode($id);
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param array<string,mixed> $o
     *
     * @return array<string,mixed>
     */
    private static function defined(array $o): array
    {
        return array_filter($o, static fn ($v) => $v !== null);
    }

    private static function level(Level|string $l): string
    {
        return $l instanceof Level ? $l->value : $l;
    }

    /**
     * @param list<Action>|null $actions
     *
     * @return list<array<string,mixed>>|null
     */
    private static function actionsToWire(?array $actions): ?array
    {
        return $actions === null ? null : array_map(
            static fn (Action $a) => self::defined(['id' => $a->id, 'title' => $a->title, 'ack' => $a->ack, 'kind' => $a->kind]),
            array_values($actions),
        );
    }

    /** @param array<string,mixed> $w */
    private static function actionFromWire(mixed $w): Action
    {
        $w = is_array($w) ? $w : [];

        return new Action(
            self::str($w, 'id'),
            self::str($w, 'title'),
            isset($w['ack']) && is_bool($w['ack']) ? $w['ack'] : null,
            isset($w['kind']) && is_string($w['kind']) ? $w['kind'] : null,
        );
    }

    /** @return array<string,mixed>|null */
    private static function alertToWire(?ActivityAlert $a): ?array
    {
        return $a === null ? null : self::defined(['title' => $a->title, 'body' => $a->body]);
    }

    /** @return array<string,mixed>|null */
    private static function audienceToWire(?Audience $a): ?array
    {
        return $a === null ? null : self::defined([
            'mode' => $a->mode instanceof AudienceMode ? $a->mode->value : $a->mode,
            'users' => $a->users === [] ? null : array_values($a->users),
            'groups' => $a->groups === [] ? null : array_values($a->groups),
        ]);
    }

    private static function audienceFromWire(mixed $w): ?Audience
    {
        if (!is_array($w)) {
            return null;
        }
        $list = static fn (mixed $v): array => is_array($v) ? array_values(array_map('strval', array_filter($v, 'is_scalar'))) : [];

        return new Audience(self::str($w, 'mode'), $list($w['users'] ?? null), $list($w['groups'] ?? null));
    }

    /** @param array<string,mixed> $w */
    private static function groupFromWire(array $w, string $id): Group
    {
        $members = isset($w['members']) && is_array($w['members']) ? array_values(array_map('strval', array_filter($w['members'], 'is_scalar'))) : [];

        return new Group(self::str($w, 'id') ?: $id, $members);
    }

    /** @return list<Recipient> */
    private static function recipients(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $r) {
            $r = is_array($r) ? $r : [];
            $id = self::str($r, 'message_id');
            $out[] = new Recipient(
                self::str($r, 'external_user_id'),
                self::str($r, 'status'),
                $id === '' ? null : $id,
                self::int($r, 'devices'),
                self::deliveries($r['deliveries'] ?? null),
            );
        }

        return $out;
    }

    /** @return list<Delivery> */
    private static function deliveries(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $d) {
            $d = is_array($d) ? $d : [];
            $err = self::str($d, 'error');
            $out[] = new Delivery(self::str($d, 'device_id'), self::str($d, 'status'), $err === '' ? null : $err);
        }

        return $out;
    }

    /** @param array<mixed> $w */
    private static function str(array $w, string $key): string
    {
        $v = $w[$key] ?? '';

        return is_string($v) ? $v : (is_scalar($v) ? (string) $v : '');
    }

    /** @param array<mixed> $w */
    private static function int(array $w, string $key): int
    {
        $v = $w[$key] ?? 0;

        return is_numeric($v) ? (int) $v : 0;
    }
}
