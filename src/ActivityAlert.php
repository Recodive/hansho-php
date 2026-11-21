<?php

declare(strict_types=1);

namespace Recodive\Hansho;

final class ActivityAlert
{
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $body = null,
    ) {
    }
}
