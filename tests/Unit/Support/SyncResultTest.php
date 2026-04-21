<?php

namespace Guarzo\Seat\WandererSync\Tests\Unit\Support;

use Guarzo\Seat\WandererSync\Support\SyncResult;
use PHPUnit\Framework\TestCase;

final class SyncResultTest extends TestCase
{
    public function test_no_op_is_zero_across_the_board(): void
    {
        $r = SyncResult::noOp();
        $this->assertSame(0, $r->added);
        $this->assertSame(0, $r->removed);
        $this->assertSame([], $r->failed);
    }

    public function test_carries_counts_and_failed_ids(): void
    {
        $r = new SyncResult(added: 3, removed: 2, failed: [42, 43]);
        $this->assertSame(3, $r->added);
        $this->assertSame(2, $r->removed);
        $this->assertSame([42, 43], $r->failed);
    }

    public function test_summary_format(): void
    {
        $r = new SyncResult(added: 3, removed: 2, failed: [42]);
        $this->assertSame('added=3 removed=2 failed=1', $r->summary());
    }
}
