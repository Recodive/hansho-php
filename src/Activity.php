<?php

declare(strict_types=1);

namespace Recodive\Hansho;

final class Activity
{
    public function __construct(
        public readonly string $externalUserId,
        public readonly string $channel,
        public readonly string $templateId,
        public readonly mixed $data,
        public readonly ?ActivityAlert $alert = null,
    ) {
    }
}
