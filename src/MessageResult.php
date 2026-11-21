<?php

declare(strict_types=1);

namespace Recodive\Hansho;

final class MessageResult
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $level,
        public readonly int $devices,
        public readonly string $status,
        /** @var list<Delivery> */
        public readonly array $deliveries,
    ) {
    }
}
