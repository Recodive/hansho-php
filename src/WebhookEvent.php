<?php

declare(strict_types=1);

namespace Recodive\Hansho;

/**
 * A verified webhook. Switch on {@see $type}; an event this SDK does not know has type "unknown".
 *
 * {@see $data} has camelCase keys for known events:
 *  - pairing.created:      pairingId, externalUserId, deviceName
 *  - pairing.revoked:      pairingId, externalUserId, by ("sender", "device" or "relay")
 *  - message.delivered:    messageId, externalUserId, channel, level, devices
 *  - message.undeliverable: messageId, externalUserId, channel, reason
 *  - message.acked:        messageId, externalUserId, deviceId
 *  - message.dismissed:    messageId, externalUserId, deviceId
 *  - message.expired:      messageId, externalUserId, channel, attempts
 *  - action:               messageId, actionId, deviceId, externalUserId, text (only for text actions)
 *  - activity.ended:       activityId, externalUserId, channel, reason
 * For "unknown", {@see $name} is the event name as sent and `data` the payload as parsed JSON, untouched.
 */
final class WebhookEvent
{
    /**
     * @param array<string,mixed>|mixed $data
     * @param string                    $raw  the verified request body
     */
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly \DateTimeImmutable $createdAt,
        public readonly mixed $data,
        public readonly string $raw,
        public readonly ?string $name = null,
    ) {
    }
}
