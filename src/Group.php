<?php

declare(strict_types=1);

namespace Recodive\Hansho;

/** A named list of users, used in audiences. */
final class Group
{
    public function __construct(
        public readonly string $id,
        /** @var list<string> */
        public readonly array $members,
    ) {
    }
}
