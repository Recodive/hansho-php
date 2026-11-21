<?php

declare(strict_types=1);

namespace Recodive\Hansho;

final class ActivityUpdate
{
    public function __construct(
        public readonly mixed $data,
        /** 5 (default) or 10. */
        public readonly ?int $priority = null,
        public readonly ?ActivityAlert $alert = null,
    ) {
    }
}
