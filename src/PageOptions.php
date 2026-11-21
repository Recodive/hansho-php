<?php

declare(strict_types=1);

namespace Recodive\Hansho;

final class PageOptions
{
    public function __construct(
        /** Seconds between resends (hub default 120). */
        public readonly ?int $intervalSeconds = null,
        /** Resends before the page expires (hub default 5). */
        public readonly ?int $maxRetries = null,
    ) {
    }
}
