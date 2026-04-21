<?php

namespace Guarzo\Seat\WandererSync\Tests\Unit\Support;

use Guarzo\Seat\WandererSync\Support\TokenSanitizer;
use PHPUnit\Framework\TestCase;

final class TokenSanitizerTest extends TestCase
{
    public function test_null_returns_none_literal(): void
    {
        $this->assertSame('None', TokenSanitizer::maskApiKey(null));
    }

    public function test_empty_string_returns_none_literal(): void
    {
        $this->assertSame('None', TokenSanitizer::maskApiKey(''));
    }

    public function test_short_string_is_all_stars(): void
    {
        $this->assertSame('****', TokenSanitizer::maskApiKey('abcd'));
        $this->assertSame('**', TokenSanitizer::maskApiKey('ab'));
    }

    public function test_long_string_shows_last_four_chars(): void
    {
        $this->assertSame('***f456', TokenSanitizer::maskApiKey('abc123def456'));
    }

    public function test_visible_chars_argument_controls_suffix_length(): void
    {
        $this->assertSame('***56', TokenSanitizer::maskApiKey('abc123def456', 2));
    }
}
