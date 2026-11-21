<?php

declare(strict_types=1);

namespace Recodive\Hansho;

/** A button shown with a message or channel. An `ack` action acknowledges a page. */
final class Action
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?bool $ack = null,
        public readonly ?string $kind = null,
    ) {
    }
}
