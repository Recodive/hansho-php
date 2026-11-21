<?php

declare(strict_types=1);

namespace Recodive\Hansho\Exception;

/**
 * Thrown by {@see \Recodive\Hansho\Webhook::verify()}. Check {@see $reason}: one of malformed_header,
 * bad_signature, stale, malformed_body, invalid_body, invalid_secret.
 */
final class WebhookException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
