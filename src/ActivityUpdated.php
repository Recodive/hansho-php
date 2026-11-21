<?php

declare(strict_types=1);

namespace Recodive\Hansho;

final class ActivityUpdated
{
    public function __construct(
        public readonly string $activityId,
        public readonly int $update,
    ) {
    }
}
