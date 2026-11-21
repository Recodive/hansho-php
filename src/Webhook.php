<?php

declare(strict_types=1);

namespace Recodive\Hansho;

use Recodive\Hansho\Exception\WebhookException;

/**
 * Verifies the hub's webhooks. The hub signs `t + "." + body` with HMAC-SHA256 and sends
 * `X-Hansho-Webhook-Signature: t=<unix>,v1=<hex>`.
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'X-Hansho-Webhook-Signature';
    /** Default allowed distance between the signed timestamp and now, in seconds. */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Verifies a webhook against its raw body and returns the event. With several v1 values in the header (secret
     * rotation) any match is accepted.
     *
     * @param string      $secret           the shared secret (HUB_WEBHOOK_SECRET)
     * @param string|null $header           the X-Hansho-Webhook-Signature request header
     * @param mixed       $body             the request body exactly as received (file_get_contents('php://input')); a body
     *                                      that a framework parsed and you re-serialised will not verify, and a non-string is rejected
     * @param int|null    $now              the current unix time in seconds; default: now
     * @param int|null    $toleranceSeconds allowed distance between signed timestamp and now; null or <= 0 means 300
     *
     * @throws WebhookException reason: malformed_header, bad_signature, stale, malformed_body, invalid_body, invalid_secret
     */
    public static function verify(string $secret, ?string $header, mixed $body, ?int $now = null, ?int $toleranceSeconds = null): WebhookEvent
    {
        if ($secret === '') {
            throw new WebhookException('invalid_secret', 'webhook secret is empty');
        }
        if (!is_string($body)) {
            throw new WebhookException('invalid_body', 'body must be the raw request body (a string), not a parsed value');
        }
        [$t, $sigs] = self::parseHeader($header);
        $want = hash_hmac('sha256', $t . '.' . $body, $secret);
        $match = false;
        foreach ($sigs as $sig) {
            if (preg_match('/^[0-9a-fA-F]{64}$/D', $sig) !== 1) {
                continue;
            }
            if (hash_equals($want, strtolower($sig))) {
                $match = true;
            }
        }
        if (!$match) {
            throw new WebhookException('bad_signature', 'signature does not match');
        }
        $tol = $toleranceSeconds !== null && $toleranceSeconds > 0 ? $toleranceSeconds : self::DEFAULT_TOLERANCE_SECONDS;
        if (abs(($now ?? time()) - (float) $t) > $tol) {
            throw new WebhookException('stale', 'timestamp outside tolerance');
        }

        $obj = json_decode($body);
        $env = json_decode($body, true);
        if (!$obj instanceof \stdClass || !is_array($env)) {
            throw new WebhookException('malformed_body', 'body is not a webhook envelope');
        }
        $name = $env['event'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new WebhookException('malformed_body', 'body has no event');
        }
        $data = $env['data'] ?? null;
        $payload = $obj->data ?? null;
        $d = is_array($data) && $payload instanceof \stdClass ? $data : [];
        $id = self::s($env['id'] ?? null);
        $created = (new \DateTimeImmutable('@0'))->setTimestamp((int) self::n($env['created_at'] ?? null));
        $mapped = self::mapPayload($name, $d);
        if ($mapped === null) {
            return new WebhookEvent('unknown', $id, $created, $data, $body, $name);
        }

        return new WebhookEvent($name, $id, $created, $mapped, $body);
    }

    /** @return array{0:string,1:list<string>} */
    private static function parseHeader(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            throw new WebhookException('malformed_header', 'missing signature header');
        }
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            $eq = strpos($part, '=');
            if ($eq === false) {
                throw new WebhookException('malformed_header', 'signature header part is not key=value');
            }
            $k = substr($part, 0, $eq);
            $v = substr($part, $eq + 1);
            if ($k === 't') {
                if ($t !== null) {
                    throw new WebhookException('malformed_header', 'duplicate timestamp');
                }
                $t = $v;
            } elseif ($k === 'v1' && $v !== '') {
                $sigs[] = $v;
            }
        }
        if ($t === null || preg_match('/^-?\d+$/D', $t) !== 1 || $sigs === []) {
            throw new WebhookException('malformed_header', 'signature header needs t=<unix> and v1=<hex>');
        }

        return [$t, $sigs];
    }

    private static function s(mixed $v): string
    {
        return is_string($v) ? $v : (is_scalar($v) ? (string) $v : '');
    }

    private static function n(mixed $v): int|float
    {
        return is_numeric($v) ? $v + 0 : 0;
    }

    /**
     * @param array<string,mixed> $d
     *
     * @return array<string,mixed>|null null for an event this SDK does not know
     */
    private static function mapPayload(string $type, array $d): ?array
    {
        $s = self::s(...);
        switch ($type) {
            case 'pairing.created':
                return ['pairingId' => $s($d['pairing_id'] ?? null), 'externalUserId' => $s($d['external_user_id'] ?? null), 'deviceName' => $s($d['device_name'] ?? null)];
            case 'pairing.revoked':
                return ['pairingId' => $s($d['pairing_id'] ?? null), 'externalUserId' => $s($d['external_user_id'] ?? null), 'by' => $s($d['by'] ?? null)];
            case 'message.delivered':
                return ['messageId' => $s($d['message_id'] ?? null), 'externalUserId' => $s($d['external_user_id'] ?? null), 'channel' => $s($d['channel'] ?? null), 'level' => $s($d['level'] ?? null), 'devices' => (int) self::n($d['devices'] ?? null)];
            case 'message.undeliverable':
                return ['messageId' => $s($d['message_id'] ?? null), 'externalUserId' => $s($d['external_user_id'] ?? null), 'channel' => $s($d['channel'] ?? null), 'reason' => $s($d['reason'] ?? null)];
            case 'message.acked':
            case 'message.dismissed':
                return ['messageId' => $s($d['message_id'] ?? null), 'externalUserId' => $s($d['external_user_id'] ?? null), 'deviceId' => $s($d['device_id'] ?? null)];
            case 'message.expired':
                return ['messageId' => $s($d['message_id'] ?? null), 'externalUserId' => $s($d['external_user_id'] ?? null), 'channel' => $s($d['channel'] ?? null), 'attempts' => (int) self::n($d['attempts'] ?? null)];
            case 'action':
                $out = ['messageId' => $s($d['message_id'] ?? null), 'actionId' => $s($d['action_id'] ?? null), 'deviceId' => $s($d['device_id'] ?? null), 'externalUserId' => $s($d['external_user_id'] ?? null)];
                if (isset($d['text']) && is_string($d['text'])) {
                    $out['text'] = $d['text'];
                }

                return $out;
            case 'activity.ended':
                return ['activityId' => $s($d['activity_id'] ?? null), 'externalUserId' => $s($d['external_user_id'] ?? null), 'channel' => $s($d['channel'] ?? null), 'reason' => $s($d['reason'] ?? null)];
            default:
                return null;
        }
    }
}
