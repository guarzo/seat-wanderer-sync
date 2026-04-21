<?php

namespace Guarzo\Seat\WandererSync\Tests\Unit\Support;

use Guarzo\Seat\WandererSync\Support\Outcome;
use PHPUnit\Framework\TestCase;

final class OutcomeTest extends TestCase
{
    public function test_success(): void
    {
        $o = Outcome::success();
        $this->assertTrue($o->isSuccess());
        $this->assertFalse($o->isExisted());
        $this->assertFalse($o->isError());
        $this->assertNull($o->reasonKey());
    }

    public function test_existed(): void
    {
        $o = Outcome::existed();
        $this->assertFalse($o->isSuccess());
        $this->assertTrue($o->isExisted());
        $this->assertFalse($o->isError());
        $this->assertNull($o->reasonKey());
    }

    public function test_error_carries_reason_key(): void
    {
        $o = Outcome::error('url_invalid');
        $this->assertFalse($o->isSuccess());
        $this->assertFalse($o->isExisted());
        $this->assertTrue($o->isError());
        $this->assertSame('url_invalid', $o->reasonKey());
    }
}
