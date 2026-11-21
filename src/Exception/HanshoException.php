<?php

declare(strict_types=1);

namespace Recodive\Hansho\Exception;

/** Base of everything the client throws for a request: {@see ApiException} or {@see NetworkException}. */
abstract class HanshoException extends \RuntimeException
{
}
