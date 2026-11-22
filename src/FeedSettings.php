<?php

declare(strict_types=1);

namespace Recodive\Hansho;

/** A feed's settings without its data. `audience` is null while the feed inherits its channel's. */
final class FeedSettings
{
    public function __construct(
        public readonly string $feedId,
        public readonly string $templateId,
        public readonly ?string $externalUserId = null,
        public readonly ?string $title = null,
        public readonly ?string $channel = null,
        public readonly ?Audience $audience = null,
        /** Unix seconds of the last update. */
        public readonly int $updatedAt = 0,
    ) {
    }
}
