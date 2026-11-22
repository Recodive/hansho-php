<?php

declare(strict_types=1);

namespace Recodive\Hansho;

/**
 * Who may use a channel or feed. Users are your external user ids (they may not have paired yet) and groups are group
 * ids (see Hansho::putGroup(); a group that does not exist has no members). Both must be empty for Everyone.
 */
final class Audience
{
    public function __construct(
        public readonly AudienceMode|string $mode,
        /** @var list<string> */
        public readonly array $users = [],
        /** @var list<string> */
        public readonly array $groups = [],
    ) {
    }
}
