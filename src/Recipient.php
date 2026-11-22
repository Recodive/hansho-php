<?php

declare(strict_types=1);

namespace Recodive\Hansho;

/**
 * The outcome of a send for one user. `status` is the message's status (queued, undeliverable) or
 * Recipient::NO_ACCESS, in which case there is no message.
 */
final class Recipient
{
    /** The user is outside the channel's audience: no message and no push. */
    public const NO_ACCESS = 'no_access';

    public function __construct(
        public readonly string $externalUserId,
        public readonly string $status,
        public readonly ?string $messageId = null,
        public readonly int $devices = 0,
        /** @var list<Delivery> */
        public readonly array $deliveries = [],
    ) {
    }
}
