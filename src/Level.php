<?php

declare(strict_types=1);

namespace Recodive\Hansho;

/** How loudly a message is presented, lowest to highest. Anywhere a Level is accepted its string value works too. */
enum Level: string
{
    case Passive = 'passive';
    case Active = 'active';
    case TimeSensitive = 'time-sensitive';
    case Critical = 'critical';
    case Page = 'page';
}
