<?php

declare(strict_types=1);

namespace Recodive\Hansho;

/** `status` is one of queued, delivered, undeliverable, paging, acked, expired. */
final class MessageStatus
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $status,
        public readonly string $level,
        public readonly int $attempts,
        /** @var list<Delivery> */
        public readonly array $deliveries,
        public readonly ?\DateTimeImmutable $ackedAt = null,
    ) {
    }
}
