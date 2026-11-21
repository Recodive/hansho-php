<?php

declare(strict_types=1);

namespace Recodive\Hansho;

final class Feed
{
    public function __construct(
        public readonly string $templateId,
        public readonly mixed $data,
        /** Omit for a feed shared by all paired users. */
        public readonly ?string $externalUserId = null,
        /** The name the app's feed picker shows instead of the id (at most 64 bytes). */
        public readonly ?string $title = null,
    ) {
    }
}
