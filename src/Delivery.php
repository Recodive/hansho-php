<?php

declare(strict_types=1);

namespace Recodive\Hansho;

final class Delivery
{
    public function __construct(
        public readonly string $deviceId,
        public readonly string $status,
        public readonly ?string $error = null,
    ) {
    }
}
