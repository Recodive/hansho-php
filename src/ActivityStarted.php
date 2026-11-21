<?php

declare(strict_types=1);

namespace Recodive\Hansho;

final class ActivityStarted
{
    public function __construct(
        public readonly string $activityId,
        public readonly int $devices,
    ) {
    }
}
