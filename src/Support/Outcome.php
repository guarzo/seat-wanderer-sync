<?php

namespace Guarzo\Seat\WandererSync\Support;

final class Outcome
{
    private function __construct(
        private readonly string $kind,
        private readonly ?string $reasonKey,
    ) {}

    public static function success(): self { return new self('success', null); }
    public static function existed(): self { return new self('existed', null); }
    public static function error(string $reasonKey): self { return new self('error', $reasonKey); }

    public function isSuccess(): bool { return $this->kind === 'success'; }
    public function isExisted(): bool { return $this->kind === 'existed'; }
    public function isError(): bool { return $this->kind === 'error'; }
    public function reasonKey(): ?string { return $this->reasonKey; }
}
