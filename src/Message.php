<?php

declare(strict_types=1);

namespace Recodive\Hansho;

/**
 * A notification, or a page when the level is Level::Page.
 *
 * `data` is sent as given (keys are never renamed); pass a stdClass for an empty JSON object.
 */
final class Message
{
    public function __construct(
        public readonly string $externalUserId,
        public readonly string $channel,
        public readonly string $title,
        public readonly string $body,
        public readonly Level|string|null $level = null,
        public readonly ?string $subtitle = null,
        public readonly ?string $imageUrl = null,
        public readonly ?string $templateId = null,
        public readonly mixed $data = null,
        public readonly ?string $sound = null,
        /** @var list<Action>|null */
        public readonly ?array $actions = null,
        public readonly ?string $collapseId = null,
        public readonly ?PageOptions $page = null,
        /**
         * Opt in to retries of 429 and 503 for this send. Sent as the Idempotency-Key header, never in the body.
         * The hub ignores it today, so a retried send is at-least-once.
         */
        public readonly ?string $idempotencyKey = null,
    ) {
    }
}
