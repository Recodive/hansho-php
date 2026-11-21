<?php

declare(strict_types=1);

namespace Recodive\Hansho;

final class PairingTicket
{
    public function __construct(
        public readonly string $ticket,
        /** Render this string as a QR code for the user to scan in the app. */
        public readonly string $qr,
        public readonly \DateTimeImmutable $expiresAt,
    ) {
    }
}
