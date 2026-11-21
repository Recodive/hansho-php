<?php

declare(strict_types=1);

namespace Recodive\Hansho;

final class SoundResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $filename,
        public readonly string $sha256,
        public readonly int $size,
    ) {
    }
}
