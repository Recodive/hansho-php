# recodive/hansho

PHP client for a Hansho hub. PHP 8.1 or newer, no runtime dependencies beyond
`ext-json` and `ext-curl`. PSR-4 namespace `Recodive\Hansho`.

```sh
composer require recodive/hansho
```

See [../README.md](../README.md) for the model shared with the other SDKs.
Licence: MIT (see [../LICENSE](../LICENSE)). The hub itself is AGPL-3.0.

## Quick start

```php
use Recodive\Hansho\{Channel, Hansho, Level, Message};

$hansho = new Hansho('https://hub.example.com', getenv('HANSHO_API_KEY'));

$hansho->putChannel('ops', new Channel('Operations', Level::Active, Level::Critical));

$res = $hansho->sendMessage(new Message(
    externalUserId: 'user-42',
    channel: 'ops',
    title: 'Disk full',
    body: 'db-1 is at 97%',
));
echo $res->messageId, ' ', $res->status; // msg_... queued
```

Constructor arguments: `baseUrl`, `apiKey`, then named options `maxRetries`
(default 3, 0 disables), `timeoutMs` (default 30000, 0 disables), `userAgent`,
`transport` (an `Http\Transport`, for proxies or tests; the default uses ext-curl)
and `sleep` (replaces the retry wait; for tests).

Levels are the `Level` enum (`Passive`, `Active`, `TimeSensitive`, `Critical`,
`Page`); the matching strings (`'page'`) work too. Requests and results are small
immutable classes with camelCase properties. `data` and template bodies are sent
exactly as given (keys are never renamed); use `new stdClass()` for an empty JSON
object, since an empty PHP array encodes as `[]`.

## Pairing

```php
$ticket = $hansho->createPairingTicket('user-42');
// Render $ticket->qr as a QR code; the user scans it in the Hansho app.
// $ticket->expiresAt is 10 minutes out; the ticket works once.
// The hub then sends a pairing.created webhook.

$hansho->unpair('user-42'); // revokes every pairing of the user
$hansho->kick('user-42');   // the same call; the app tells the person that you ended it
```

## Audiences and groups

A channel or feed is open to everyone unless it has an `Audience`: `AudienceMode::Only` the listed users and groups, or
`AudienceMode::Except` them. Users are your external user ids (they may not have paired yet); groups are named lists you
keep on the hub. The hub decides who has access and checks it on every request, so a change applies at once.

```php
use Recodive\Hansho\{Audience, AudienceMode, Channel, Feed, Level, Message, Recipient};

$hansho->putGroup('oncall', ['alice', 'bob']); // replaces the whole member list; also getGroup, deleteGroup
$hansho->putChannel('pager', new Channel('Pager', Level::Page, Level::Page,
    audience: new Audience(AudienceMode::Only, groups: ['oncall'])));
// A null audience keeps the audience stored on the hub; new Audience(AudienceMode::Everyone) opens the channel up again.

// A feed uses its channel's audience unless it sets its own. Leaving both out keeps what is stored; naming a channel
// without an audience makes the feed use the channel's.
$hansho->putFeed('queue', new Feed('status', ['value' => 3], channel: 'pager'));
$settings = $hansho->getFeed('queue'); // settings without the data; getFeed('queue', 'user-42') for a per-user feed

// Send to several users, or to everyone in the channel's audience. People outside it are not delivered to.
// externalUserId stays the first argument: pass null when you use externalUserIds or toAudience.
$res = $hansho->sendMessage(new Message(null, 'pager', 'DB down', '...', toAudience: true));
foreach ($res->recipients as $r) {
    if ($r->status === Recipient::NO_ACCESS) { /* only with externalUserId(s): not in the audience, no messageId */ }
}
```

`$res->recipients` has one entry per user. A send to one `externalUserId` keeps filling the top-level `messageId`,
`devices`, `status` and `deliveries` too (for a user without access: empty `messageId`, `devices` 0, status `no_access`);
for several users or an audience the top-level `messageId` is empty and each message is under `recipients`. An audience
that names a group that does not exist is refused with `400 invalid_request`. Error codes on `ApiException::$apiCode`:
`ApiException::NO_ACCESS` (403, `startActivity` for a user outside the channel's audience) and
`ApiException::UNKNOWN_GROUP` (404, `getGroup`, `deleteGroup`).

## Sending

```php
use Recodive\Hansho\{Action, Activity, ActivityAlert, ActivityUpdate, Feed, PageOptions};

// A notification. The level defaults to the channel's default and is capped at its max.
$hansho->sendMessage(new Message(
    externalUserId: 'user-42', channel: 'ops', level: Level::TimeSensitive,
    title: 'Deploy failed', body: 'web-3 rolled back',
    actions: [new Action('retry', 'Retry')],
    collapseId: 'deploy-web', // a later message with the same id replaces this one
));

// A page repeats every intervalSeconds until someone acknowledges it on any
// device, then the hub sends message.acked. Defaults: 120 s, 5 resends.
$page = $hansho->sendMessage(new Message(
    externalUserId: 'user-42', channel: 'ops', level: Level::Page,
    title: 'Primary DB down', body: 'Failover did not complete',
    page: new PageOptions(intervalSeconds: 60, maxRetries: 10),
));
$status = $hansho->getMessage($page->messageId); // queued, delivered, undeliverable, paging, acked, expired
// $status->status, ->attempts, ->deliveries, ->ackedAt (DateTimeImmutable|null)

// A templated message.
$hansho->putTemplate('status', 1, ['type' => 'vstack', 'children' => [['type' => 'text', 'style' => 'headline', 'text' => '{{title}}']]]);
$hansho->sendMessage(new Message('user-42', 'ops', 'Uptime', '99.98%', templateId: 'status', data: ['title' => 'Uptime']));

// A Live Activity.
$act = $hansho->startActivity(new Activity('user-42', 'ops', 'deploy', ['progress' => 0.1], new ActivityAlert('Deploy started')));
$hansho->updateActivity($act->activityId, new ActivityUpdate(['progress' => 0.6], priority: 10));
$hansho->endActivity($act->activityId);

// A widget feed; omit externalUserId for one shared by all paired users.
$hansho->putFeed('uptime', new Feed('status', ['title' => 'up'], externalUserId: 'user-42', title: 'Uptime'));

// A sound (audio/x-caf, audio/wav or audio/aiff, at most 1 MB).
$hansho->putSound('ding', 'audio/wav', file_get_contents('ding.wav'));
```

Channels: `putChannel`, `getChannel`, `deleteChannel`. Ids may contain any
characters except `.` and `..`: they are path-escaped.

## Webhooks

Set `HUB_WEBHOOK_URL` and `HUB_WEBHOOK_SECRET` on the hub. Verify the **raw**
request body: a body a framework parsed and you re-serialised will not match its
signature, and `Webhook::verify` refuses anything that is not a string.

```php
use Recodive\Hansho\Exception\WebhookException;
use Recodive\Hansho\Webhook;

try {
    $event = Webhook::verify(
        secret: getenv('HANSHO_WEBHOOK_SECRET'),
        header: $_SERVER['HTTP_X_HANSHO_WEBHOOK_SIGNATURE'] ?? null,
        body: file_get_contents('php://input'),
    );
    switch ($event->type) {
        case 'message.acked': error_log('acknowledged: ' . $event->data['messageId']); break;
        case 'action': error_log('button ' . $event->data['actionId'] . ' on ' . $event->data['messageId']); break;
        case 'unknown': error_log('newer hub event: ' . $event->name); break;
    }
    http_response_code(200);
} catch (WebhookException $e) {
    http_response_code(400); // non-2xx: the hub retries
}
```

In Laravel use `$request->getContent()` (not `$request->all()`); in Symfony
`$request->getContent()`; in PSR-7, `(string) $request->getBody()`.

`Webhook::verify` accepts several `v1=` signatures (secret rotation), compares in
constant time and rejects timestamps more than 300 s from now (`toleranceSeconds`
changes it; `now` is injectable, both in seconds). `WebhookException::$reason` is one of
`malformed_header`, `bad_signature`, `stale` (a valid signature, replayed too
late), `malformed_body`, `invalid_body`, `invalid_secret`. Events:
`pairing.created`, `pairing.revoked`, `message.delivered`,
`message.undeliverable`, `message.acked`, `message.dismissed`, `message.expired`,
`action`, `activity.ended`, with camelCase keys in `$event->data`; anything else is
`type 'unknown'` with the event name in `$event->name` and the parsed payload in
`$event->data`. `$event->raw` is the verified body.

## Errors

```php
use Recodive\Hansho\Exception\{ApiException, NetworkException};

try {
    $hansho->sendMessage($msg);
} catch (ApiException $e) {
    // $e->status, $e->apiCode ('unknown_channel', 'invalid_request', ...),
    // $e->field ('title'), $e->retryAfterMs, $e->detail
} catch (NetworkException $e) {
    // no answer at all (connection, timeout, unreadable reply); see $e->getPrevious()
}
```

Both extend `Exception\HanshoException`. Error messages never contain the API
key, ticket values or message bodies. Invalid arguments (an empty id, `.`/`..`,
an oversized sound) throw `InvalidArgumentException` / `RangeException` before
anything is sent.

## Retries

`GET`, `PUT` and `DELETE` are retried on 429 and 503, up to `maxRetries` times,
waiting for `Retry-After` (a wait over 60 s is thrown to you as the error) or an
exponential backoff with jitter. `sendMessage` is never retried unless the
message has an `idempotencyKey`, sent as the `Idempotency-Key` header; the hub
does not deduplicate on it yet, so a retried send is at-least-once. Activity
updates (`PATCH`) are not retried. The retry wait blocks the PHP process.

## Tests

    composer install
    vendor/bin/phpunit                         # unit tests (loads ../spec/*.json)
    ../scripts/run-integration.sh              # also runs the integration suite against the real hub

The integration suite (`--testsuite integration`) is skipped unless
`HANSHO_HUB_URL` is set; it also reads `HANSHO_API_KEY`, `HANSHO_WEBHOOK_SECRET`,
`HANSHO_WEBHOOK_ADDR` and `HANSHO_FAKEDEVICE_BIN`.
