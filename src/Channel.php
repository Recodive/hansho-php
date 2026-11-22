<?php

declare(strict_types=1);

namespace Recodive\Hansho;

final class Channel
{
    public function __construct(
        public readonly string $name,
        public readonly Level|string $defaultLevel,
        public readonly Level|string $maxLevel,
        public readonly ?string $sound = null,
        public readonly ?string $templateId = null,
        public readonly ?string $thread = null,
        /** @var list<Action>|null */
        public readonly ?array $actions = null,
        /** Who may use the channel. Null keeps the audience stored on the hub; Everyone opens it up again. getChannel() always returns one. */
        public readonly ?Audience $audience = null,
    ) {
    }
}
