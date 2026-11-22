<?php

declare(strict_types=1);

namespace Recodive\Hansho;

/**
 * `recipients` has one entry per user. For a send to `externalUserId` the other fields describe that one recipient
 * (`messageId` is empty, `status` is 'no_access' and `devices` is 0 when the user is outside the audience); for several
 * users or an audience, `messageId`, `devices` and `deliveries` are empty and each message is under `recipients`.
 */
final class MessageResult
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $level,
        public readonly int $devices,
        public readonly string $status,
        /** @var list<Delivery> */
        public readonly array $deliveries,
        /** @var list<Recipient> */
        public readonly array $recipients = [],
    ) {
    }
}
