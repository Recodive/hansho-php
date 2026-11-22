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
        /** The channel whose audience the feed uses. */
        public readonly ?string $channel = null,
        /**
         * The feed's own audience, instead of its channel's. Leaving both out keeps what is stored on the hub; naming a
         * channel without an audience makes the feed use the channel's.
         */
        public readonly ?Audience $audience = null,
    ) {
    }
}
