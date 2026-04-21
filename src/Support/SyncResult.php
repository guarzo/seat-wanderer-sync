<?php

namespace Guarzo\Seat\WandererSync\Support;

final class SyncResult
{
    /** @param  int[]  $failed */
    public function __construct(
        public readonly int $added = 0,
        public readonly int $removed = 0,
        public readonly array $failed = [],
    ) {}

    public static function noOp(): self { return new self(); }

    public function summary(): string
    {
        return sprintf('added=%d removed=%d failed=%d', $this->added, $this->removed, count($this->failed));
    }
}
