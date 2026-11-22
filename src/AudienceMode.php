<?php

declare(strict_types=1);

namespace Recodive\Hansho;

/** How an Audience reads its lists. */
enum AudienceMode: string
{
    /** Every paired user is let in. */
    case Everyone = 'everyone';
    /** Only the listed users and the members of the listed groups. */
    case Only = 'only';
    /** Everyone but the listed users and the members of the listed groups. */
    case Except = 'except';
}
