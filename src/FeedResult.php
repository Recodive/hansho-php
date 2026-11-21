<?php

declare(strict_types=1);

namespace Recodive\Hansho;

final class FeedResult
{
    public function __construct(
        public readonly string $feedId,
        public readonly int $devices,
    ) {
    }
}
